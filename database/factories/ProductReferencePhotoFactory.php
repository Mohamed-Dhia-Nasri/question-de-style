<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Shared\Enums\PhotoViewLabel;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). Rows are blob-less by default: tests that
 * need real bytes fake the media disk, put a file, and override
 * storage_path/checksum.
 *
 * @extends Factory<ProductReferencePhoto>
 */
class ProductReferencePhotoFactory extends Factory
{
    use ResolvesTenant;

    protected $model = ProductReferencePhoto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'product_id' => Product::factory(),
            'storage_disk' => 'media',
            'storage_path' => 'tenants/1/product-photos/1/'.fake()->unique()->uuid().'.jpg',
            'view_label' => PhotoViewLabel::Front,
            'checksum' => fake()->unique()->sha256(),
            'width' => 1024,
            'height' => 1024,
            'uploaded_by' => null,
        ];
    }
}
