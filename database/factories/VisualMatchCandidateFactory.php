<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\VisualMatchCandidate;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\VisualMatchBand;
use Carbon\CarbonImmutable;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). One ranked visual-match candidate (§4.5).
 *
 * @extends Factory<VisualMatchCandidate>
 */
class VisualMatchCandidateFactory extends Factory
{
    use ResolvesTenant;

    protected $model = VisualMatchCandidate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'visual_match_run_id' => VisualMatchRun::factory(),
            'product_id' => Product::factory(),
            'product_label' => 'Product '.fake()->unique()->numerify('####'),
            'category' => SectorLabel::Beauty,
            'rank' => 1,
            'best_similarity' => 0.7215,
            'margin_to_runner_up' => null,
            'supporting_frames' => [
                ['ordinal' => 0, 'timestamp_ms' => 0, 'similarity' => 0.7215, 'photo_id' => 1, 'represented_frames' => 1],
            ],
            'band' => VisualMatchBand::Review,
            'rejection_reason' => null,
            'source' => 'shipment',
            'shipment_in_window' => true,
            'seeding_campaign_id' => null,
            'shipment_anchor_at' => CarbonImmutable::now()->subDays(5),
            'shipment_age_days' => 5,
            'first_support_ms' => null,
            'last_support_ms' => null,
            'estimated_visible_ms' => null,
        ];
    }
}
