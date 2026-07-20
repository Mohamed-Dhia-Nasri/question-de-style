<?php

namespace Database\Factories;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Modules\Monitoring\Models\VlmVerificationRun;
use App\Platform\AiBudget\Priority;
use App\Shared\Enums\VlmRunOutcome;
use App\Shared\Enums\VlmTriggerReason;
use Database\Factories\Concerns\ResolvesTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). One VLM verification attempt-set (spec
 * §8.1). The default row is a terminal, anchored content-item
 * verification: the anchor C run is created over the SAME content item
 * (attribute-array closure — content_item_id is expanded before it runs;
 * overriding visual_match_run_id skips the closure entirely). Use
 * forAnchor() to consume an existing anchor, discovery() for DEF-021
 * 'unverifiable' rows, pending() for an open crash-safe ledger row.
 *
 * @extends Factory<VlmVerificationRun>
 */
class VlmVerificationRunFactory extends Factory
{
    use ResolvesTenant;

    protected $model = VlmVerificationRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => $this->defaultTenantId(),
            'content_item_id' => ContentItem::factory(),
            'story_id' => null,
            // The anchor covers the SAME target — C's flag row this
            // verification consumed.
            'visual_match_run_id' => fn (array $attributes) => VisualMatchRun::factory()->create([
                'content_item_id' => $attributes['content_item_id'],
                'needs_verification' => true,
            ])->id,
            'correlation_id' => fake()->uuid(),
            'model_version' => 'gemini-3.5-flash',
            'trigger_reason' => VlmTriggerReason::ReviewBand,
            'priority' => Priority::High,
            'frames_sent' => 6,
            'prompt_tokens' => 9_500,
            'output_tokens' => 800,
            'thinking_tokens' => 150,
            'attempts' => 1,
            'outcome' => VlmRunOutcome::Confirmed,
            'rejection_reason' => null,
            'thresholds' => ['auto' => 0.85, 'review' => 0.60, 'margin' => 0.10],
            'latency_ms' => 1_800,
            'estimated_cost_micro_usd' => 30_000,
        ];
    }

    /** Verification over a story instead of a content item. */
    public function inStory(): static
    {
        return $this->state(function (array $attributes): array {
            $story = Story::factory()->create();

            return [
                'content_item_id' => null,
                'story_id' => $story->id,
                'visual_match_run_id' => VisualMatchRun::factory()->create([
                    'content_item_id' => null,
                    'story_id' => $story->id,
                    'needs_verification' => true,
                ])->id,
            ];
        });
    }

    /** Consume an existing anchor run (copies its target). */
    public function forAnchor(VisualMatchRun $anchor): static
    {
        return $this->state(fn (array $attributes): array => [
            'visual_match_run_id' => $anchor->id,
            'content_item_id' => $anchor->content_item_id,
            'story_id' => $anchor->story_id,
        ]);
    }

    /** A DEF-021 discovery row: no anchor exists — never sent to Gemini. */
    public function discovery(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visual_match_run_id' => null,
            'trigger_reason' => VlmTriggerReason::UnverifiableNoRun,
            'outcome' => VlmRunOutcome::Unverifiable,
            'frames_sent' => 0,
            'prompt_tokens' => null,
            'output_tokens' => null,
            'thinking_tokens' => null,
            'attempts' => 0,
            'latency_ms' => 0,
            'estimated_cost_micro_usd' => 0,
        ]);
    }

    /** An open crash-safe ledger row (created before the first billed call). */
    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => VlmRunOutcome::Pending,
            'attempts' => 0,
            'prompt_tokens' => null,
            'output_tokens' => null,
            'thinking_tokens' => null,
            'latency_ms' => 0,
            'estimated_cost_micro_usd' => 0,
        ]);
    }
}
