<?php

namespace Database\Factories;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Platform\AiBudget\Priority;
use App\Shared\Enums\VisualMatchOutcome;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). One visual-match analysis run (spec §4.4).
 *
 * @extends Factory<VisualMatchRun>
 */
class VisualMatchRunFactory extends Factory
{
    use ResolvesTenant;

    protected $model = VisualMatchRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'content_item_id' => ContentItem::factory(),
            'story_id' => null,
            'correlation_id' => fake()->uuid(),
            'model_version' => 'gemini-embedding-2',
            'priority' => Priority::High,
            'frames_available' => 3,
            'frames_processed' => 3,
            'frames_skipped_format' => 0,
            'frames_skipped_quality' => 0,
            'frames_deduped' => 0,
            'cache_hits' => 0,
            'processing_ms' => 250,
            'candidates_checked' => 1,
            'best_score' => 0.7215,
            'outcome' => VisualMatchOutcome::Review,
            'rejection_reason' => null,
            'thresholds' => ['category_map_used' => 'default', 'auto' => 0.65, 'review' => 0.55, 'margin' => 0.05],
            'embedding_calls' => 3,
            'estimated_cost_micro_usd' => 360,
            'needs_verification' => false,
        ];
    }

    /** Run over a story instead of a content item. */
    public function inStory(): static
    {
        return $this->state(fn (array $attributes) => [
            'content_item_id' => null,
            'story_id' => Story::factory(),
        ]);
    }
}
