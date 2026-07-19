<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Shared\Enums\PhotoViewLabel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Schema + model guarantees for tenant-uploaded product reference photos
 * (visual matching sub-project C, spec §4.1): tenant stamping, enum-backed
 * view labels with a DB CHECK backstop, product-delete row cascade, and the
 * composite tenant FK that keeps every photo inside its product's workspace.
 */
class ProductReferencePhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_photos_are_tenant_stamped_with_reachable_relations(): void
    {
        $photo = ProductReferencePhoto::factory()->create(['view_label' => PhotoViewLabel::Packaging]);
        $product = Product::query()->findOrFail($photo->product_id);

        $this->assertSame($this->defaultTenant->id, $photo->tenant_id);
        $this->assertSame(PhotoViewLabel::Packaging, $photo->refresh()->view_label);
        $this->assertTrue($photo->product()->is($product));
        $this->assertTrue($product->referencePhotos()->whereKey($photo->id)->exists());
    }

    public function test_view_label_may_be_null(): void
    {
        $photo = ProductReferencePhoto::factory()->create(['view_label' => null]);

        $this->assertNull($photo->refresh()->view_label);
    }

    public function test_view_label_check_rejects_unknown_labels(): void
    {
        $product = Product::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('product_reference_photos')->insert([
            'tenant_id' => $this->defaultTenant->id,
            'product_id' => $product->id,
            'storage_disk' => 'media',
            'storage_path' => 'tenants/'.$this->defaultTenant->id.'/product-photos/'.$product->id.'/x.jpg',
            'view_label' => 'glamour',
            'checksum' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_deleting_the_product_cascades_photo_rows(): void
    {
        $photo = ProductReferencePhoto::factory()->create();
        $survivor = ProductReferencePhoto::factory()->create();

        Product::query()->findOrFail($photo->product_id)->delete();

        $this->assertDatabaseMissing('product_reference_photos', ['id' => $photo->id]);
        $this->assertDatabaseHas('product_reference_photos', ['id' => $survivor->id]);
    }

    public function test_cross_tenant_photos_violate_the_composite_product_fk(): void
    {
        $product = Product::factory()->create();       // default tenant
        $other = $this->makeTenant('Other Workspace'); // context stays on default

        try {
            DB::table('product_reference_photos')->insert([
                'tenant_id' => $other->id,
                'product_id' => $product->id,
                'storage_disk' => 'media',
                'storage_path' => 'tenants/'.$other->id.'/product-photos/'.$product->id.'/x.jpg',
                'view_label' => 'front',
                'checksum' => str_repeat('b', 64),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail("A photo row pointing at another tenant's product must violate the composite FK.");
        } catch (QueryException $e) {
            $this->assertStringContainsString('product_reference_photos_product_id_tenant_fk', $e->getMessage());
        }
    }
}
