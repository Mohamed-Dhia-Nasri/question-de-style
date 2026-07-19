<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Products\ProductsIndex;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\CRM\Models\Shipment;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec §6 lifecycle: when a product delete proceeds, photo + embedding
 * ROWS cascade at the DB inside the transaction and the photo BLOBS are
 * removed only after commit (the GDPR house order — a rolled-back delete
 * must leave every blob in place; an orphan file is recoverable, a
 * dangling row is not).
 */
class ProductDeletePhotoCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('qds.ingestion.media_disk', 'media'));

        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

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

    public function test_product_delete_cascades_photo_rows_and_removes_blobs_after_commit(): void
    {
        $product = Product::factory()->create();
        $first = $this->makeStoredPhoto($product);
        $second = $this->makeStoredPhoto($product);
        ProductPhotoEmbedding::factory()->create(['product_reference_photo_id' => $first->id]);

        Livewire::test(ProductsIndex::class)
            ->call('confirmDelete', $product->id)
            ->call('delete');

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        // Rows cascade at the DB: product → photos → embeddings.
        $this->assertDatabaseCount('product_reference_photos', 0);
        $this->assertDatabaseCount('product_photo_embeddings', 0);
        // Blobs are app-managed and go after commit.
        Storage::disk((string) $first->storage_disk)->assertMissing($first->storage_path);
        Storage::disk((string) $second->storage_disk)->assertMissing($second->storage_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.deleted', 'subject_id' => $product->id]);
    }

    public function test_a_restricted_delete_keeps_rows_and_blobs(): void
    {
        $product = Product::factory()->create();
        $photo = $this->makeStoredPhoto($product);

        // shipments.product_id restricts product deletion (Step-4 schema).
        Shipment::factory()->create(['product_id' => $product->id]);

        Livewire::test(ProductsIndex::class)
            ->call('confirmDelete', $product->id)
            ->call('delete');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('product_reference_photos', ['id' => $photo->id]);
        Storage::disk((string) $photo->storage_disk)->assertExists($photo->storage_path);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'product.deleted', 'subject_id' => $product->id]);
    }
}
