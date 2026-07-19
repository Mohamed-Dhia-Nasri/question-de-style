<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Products\ProductPhotos;
use App\Modules\CRM\Livewire\Products\ProductsIndex;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Jobs\EmbedProductPhotoJob;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\FakeEmbeddingProvider;
use Tests\TestCase;

/**
 * Reference-photo management (spec §6): private-disk blobs behind
 * short-TTL signed thumbnails (the documents precedent), server-side cap,
 * audited mutations gated on ProductPolicy::update, and the embed job
 * dispatched only when the capability can actually spend.
 */
class ProductPhotosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('qds.ingestion.media_disk', 'media'));
    }

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    /** Puts a real blob on the (faked) media disk and anchors a row to it. */
    private function makeStoredPhoto(Product $product): ProductReferencePhoto
    {
        $disk = (string) config('qds.ingestion.media_disk', 'media');
        $path = "tenants/{$product->tenant_id}/product-photos/{$product->id}/".fake()->uuid().'.jpg';
        Storage::disk($disk)->put($path, 'jpeg-bytes');

        return ProductReferencePhoto::factory()->create([
            'product_id' => $product->id,
            'storage_disk' => $disk,
            'storage_path' => $path,
        ]);
    }

    public function test_thumbnails_stream_via_signed_urls_only(): void
    {
        $this->actingAsCrmStaff();
        $photo = $this->makeStoredPhoto(Product::factory()->create());

        // Unsigned URL → rejected even for authorized staff.
        $this->get(route('crm.products.photo', ['productReferencePhoto' => $photo->id]))
            ->assertForbidden();

        $signed = URL::temporarySignedRoute(
            'crm.products.photo',
            now()->addMinutes(5),
            ['productReferencePhoto' => $photo->id],
        );

        $this->get($signed)->assertOk();
    }

    public function test_cross_tenant_photos_are_invisible_even_with_a_valid_signature(): void
    {
        [$tenantA] = $this->makeTenantPair();

        $foreign = $this->withTenant(
            $tenantA,
            fn (): ProductReferencePhoto => $this->makeStoredPhoto(Product::factory()->create()),
        );

        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));

        $signed = URL::temporarySignedRoute(
            'crm.products.photo',
            now()->addMinutes(5),
            ['productReferencePhoto' => $foreign->id],
        );

        // SetTenantContext scopes the route binding: the foreign row does
        // not exist for this tenant — 404, never 403 (no existence oracle).
        $this->get($signed)->assertNotFound();
    }

    public function test_client_viewers_cannot_view_thumbnails(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $photo = $this->makeStoredPhoto(Product::factory()->create());

        $signed = URL::temporarySignedRoute(
            'crm.products.photo',
            now()->addMinutes(5),
            ['productReferencePhoto' => $photo->id],
        );

        $this->get($signed)->assertForbidden();
    }
}
