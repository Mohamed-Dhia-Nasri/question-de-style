<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\VlmCandidateVerdict;
use App\Modules\Monitoring\Models\VlmVerificationRun;
use App\Shared\Enums\VlmBand;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). One per-candidate VLM verdict (spec §8.2).
 *
 * @extends Factory<VlmCandidateVerdict>
 */
class VlmCandidateVerdictFactory extends Factory
{
    use ResolvesTenant;

    protected $model = VlmCandidateVerdict::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'vlm_verification_run_id' => VlmVerificationRun::factory(),
            'product_id' => Product::factory(),
            'product_label' => 'Product '.fake()->unique()->numerify('####'),
            'brand_label' => 'Brand '.fake()->unique()->numerify('####'),
            'rank' => 1,
            'visible' => true,
            'spoken' => false,
            'gifting_cue' => false,
            'confidence' => 0.9100,
            'frame_timestamps' => [1_500, 4_000],
            'rationale' => 'Product clearly visible on the desk in both frames.',
            'band' => VlmBand::Auto,
            'rejection_reason' => null,
        ];
    }
}
