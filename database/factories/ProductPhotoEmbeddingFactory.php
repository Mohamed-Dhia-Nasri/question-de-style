<?php

namespace Database\Factories;

use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\Monitoring\Models\ProductPhotoEmbedding;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). Defaults to a unit basis vector — NEVER the
 * zero vector, whose cosine distance is undefined (NaN) in pgvector.
 *
 * @extends Factory<ProductPhotoEmbedding>
 */
class ProductPhotoEmbeddingFactory extends Factory
{
    use ResolvesTenant;

    protected $model = ProductPhotoEmbedding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $vector = array_fill(0, 3072, 0.0);
        $vector[0] = 1.0;

        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'product_reference_photo_id' => ProductReferencePhoto::factory(),
            'model_version' => 'gemini-embedding-2',
            'embedding' => VectorLiteral::fromArray($vector),
        ];
    }
}
