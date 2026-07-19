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

    public function test_upload_stores_the_blob_row_and_audit_event(): void
    {
        $staff = $this->actingAsCrmStaff();
        $product = Product::factory()->create();

        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->set('upload', UploadedFile::fake()->image('front.jpg', 40, 40))
            ->set('view_label', 'front')
            ->call('save')
            ->assertHasNoErrors();

        $photo = ProductReferencePhoto::query()->where('product_id', $product->id)->firstOrFail();

        // Spec §4.1: tenant-pathed private blob, sha256 checksum,
        // best-effort dimensions, uploader identity on the row.
        $this->assertStringStartsWith("tenants/{$photo->tenant_id}/product-photos/{$product->id}/", $photo->storage_path);
        Storage::disk((string) $photo->storage_disk)->assertExists($photo->storage_path);
        $this->assertSame('front', $photo->view_label?->value);
        $this->assertSame(
            hash('sha256', (string) Storage::disk((string) $photo->storage_disk)->get($photo->storage_path)),
            $photo->checksum,
        );
        $this->assertSame(40, $photo->width);
        $this->assertSame(40, $photo->height);
        $this->assertSame($staff->id, $photo->uploaded_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.photo_added', 'subject_id' => $photo->id]);
    }

    public function test_wrong_type_and_oversized_uploads_are_refused(): void
    {
        $this->actingAsCrmStaff();
        $product = Product::factory()->create();

        // Not an accepted image type (jpg/png/webp only in v1, spec §4.1).
        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->set('upload', UploadedFile::fake()->create('brief.pdf', 12))
            ->call('save')
            ->assertHasErrors(['upload']);

        // Above the 10 MB cap (max:10240 is kilobytes).
        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->set('upload', UploadedFile::fake()->create('huge.jpg', 10_241))
            ->call('save')
            ->assertHasErrors(['upload']);

        $this->assertDatabaseCount('product_reference_photos', 0);
    }

    public function test_the_photo_cap_is_enforced_server_side(): void
    {
        $this->actingAsCrmStaff();
        $product = Product::factory()->create();
        config()->set('qds.enrichment.visual_match.photo_cap', 2);

        $this->makeStoredPhoto($product);
        $this->makeStoredPhoto($product);

        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->set('upload', UploadedFile::fake()->image('side.jpg', 40, 40))
            ->call('save')
            ->assertHasErrors(['upload']);

        $this->assertSame(2, ProductReferencePhoto::query()->where('product_id', $product->id)->count());
    }

    public function test_upload_queues_the_embed_job_only_when_the_capability_can_spend(): void
    {
        Queue::fake();
        $this->actingAsCrmStaff();
        $product = Product::factory()->create();

        // Switch off (default): stored for later — qds:embed-product-photos
        // picks it up; no job, no spend.
        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->set('upload', UploadedFile::fake()->image('front.jpg', 40, 40))
            ->call('save')
            ->assertHasNoErrors();
        Queue::assertNothingPushed();

        // Switch on + configured provider: embed via the queue.
        config()->set('qds.enrichment.visual_match.enabled', true);
        $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);

        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->set('upload', UploadedFile::fake()->image('back.jpg', 40, 40))
            ->call('save')
            ->assertHasNoErrors();

        $expected = ProductReferencePhoto::query()
            ->where('product_id', $product->id)->orderByDesc('id')->firstOrFail();

        Queue::assertPushed(
            EmbedProductPhotoJob::class,
            fn (EmbedProductPhotoJob $job): bool => $job->photoId === $expected->id && $job->queue === 'enrichment',
        );
    }

    public function test_delete_removes_row_cascades_embeddings_and_blob_after_commit(): void
    {
        $this->actingAsCrmStaff();
        $product = Product::factory()->create();
        $photo = $this->makeStoredPhoto($product);

        ProductPhotoEmbedding::factory()->create(['product_reference_photo_id' => $photo->id]);

        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->call('confirmDelete', $photo->id)
            ->call('deletePhoto');

        $this->assertDatabaseMissing('product_reference_photos', ['id' => $photo->id]);
        // Embedding rows cascade at the DB (spec §4.2).
        $this->assertDatabaseMissing('product_photo_embeddings', ['product_reference_photo_id' => $photo->id]);
        Storage::disk((string) $photo->storage_disk)->assertMissing($photo->storage_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.photo_removed', 'subject_id' => $photo->id]);
    }

    public function test_mutations_require_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $product = Product::factory()->create();
        $photo = $this->makeStoredPhoto($product);

        // Opening the grid is crm.view; both mutators re-authorize update —
        // including the direct-property bypass of confirmDelete.
        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->set('upload', UploadedFile::fake()->image('front.jpg', 40, 40))
            ->call('save')->assertForbidden();

        Livewire::test(ProductPhotos::class)
            ->call('open', $product->id)
            ->set('confirmingDeleteId', $photo->id)
            ->call('deletePhoto')->assertForbidden();

        $this->assertDatabaseCount('product_reference_photos', 1);
        Storage::disk((string) $photo->storage_disk)->assertExists($photo->storage_path);
    }

    public function test_products_index_row_shows_a_photos_action_with_count(): void
    {
        $this->actingAsCrmStaff();
        $product = Product::factory()->create();
        $this->makeStoredPhoto($product);
        $this->makeStoredPhoto($product);

        Livewire::test(ProductsIndex::class)
            ->assertSee('Photos (2)');
    }
}
