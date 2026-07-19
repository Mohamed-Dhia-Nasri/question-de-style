<?php

namespace Database\Factories;

use App\Modules\Monitoring\Models\Keyframe;
use App\Modules\Monitoring\Models\KeyframeEmbedding;
use App\Platform\Enrichment\VisualMatch\Support\VectorLiteral;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). Defaults to a unit basis vector — NEVER the
 * zero vector, whose cosine distance is undefined (NaN) in pgvector.
 *
 * @extends Factory<KeyframeEmbedding>
 */
class KeyframeEmbeddingFactory extends Factory
{
    use ResolvesTenant;

    protected $model = KeyframeEmbedding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $vector = array_fill(0, 3072, 0.0);
        $vector[0] = 1.0;

        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'keyframe_id' => Keyframe::factory(),
            'model_version' => 'gemini-embedding-2',
            'embedding' => VectorLiteral::fromArray($vector),
        ];
    }
}
