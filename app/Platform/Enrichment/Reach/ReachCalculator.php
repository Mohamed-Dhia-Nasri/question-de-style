<?php

namespace App\Platform\Enrichment\Reach;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Modules\Monitoring\Models\ReachResult;
use App\Platform\Enrichment\Support\ReachConfigurationStatus;
use App\Shared\Enums\MetricTier;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\ReachEstimate;
use Carbon\CarbonImmutable;

/**
 * Estimated-reach calculation (REQ-M1-006, ADR-0022):
 *   estimated_reach = round(view_weight*views + follower_weight*followers)
 * over one ContentItem's PUBLIC views (or plays) and its author's follower
 * count, using the single ACTIVE per-tenant configuration.
 *
 * - No active configuration → NULL (reach is UNAVAILABLE, never zero).
 * - No observed input (no views AND no followers) → NULL (no fabricated row).
 * - A missing input contributes NOTHING (missing is never zero) and is
 *   disclosed in `inputs`.
 * - The stored value is a ReachEstimate at tier ESTIMATED with a disclosed
 *   method — never a raw view count (DP-001 / GL-PublicViews).
 */
class ReachCalculator
{
    public function activeConfiguration(): ?ReachConfiguration
    {
        // ACTIVE is a per-tenant notion; relies on the model's TenantScope —
        // callers run under the content item's tenant context (the enrichment
        // job wraps the pipeline in runAs).
        return ReachConfiguration::query()
            ->where('status', ReachConfigurationStatus::Active)
            ->where('effective_from', '<=', CarbonImmutable::now()->toDateString())
            ->first();
    }

    public function estimateFor(ContentItem $content): ?ReachEstimate
    {
        $configuration = $this->activeConfiguration();

        if ($configuration === null) {
            return null;
        }

        $computed = $this->compute($content, $configuration);

        return $computed === null ? null : $this->envelope($configuration, $computed['amount']);
    }

    public function calculate(ContentItem $content): ?ReachResult
    {
        $configuration = $this->activeConfiguration();

        if ($configuration === null) {
            return null;
        }

        $computed = $this->compute($content, $configuration);

        if ($computed === null) {
            return null;
        }

        return ReachResult::query()->create([
            'content_item_id' => $content->id,
            'reach_configuration_id' => $configuration->id,
            'formula_version' => $configuration->formula_version,
            'value' => $this->envelope($configuration, $computed['amount']),
            'inputs' => $computed['inputs'],
            'calculated_at' => CarbonImmutable::now(),
        ]);
    }

    private function envelope(ReachConfiguration $configuration, float $amount): ReachEstimate
    {
        return new ReachEstimate(
            $amount,
            MetricTier::Estimated,
            sprintf('%s v%s', $configuration->method, $configuration->formula_version),
        );
    }

    /**
     * @return array{amount: float, inputs: array<string, mixed>}|null
     */
    private function compute(ContentItem $content, ReachConfiguration $configuration): ?array
    {
        $views = $this->publicMetric($content, 'views') ?? $this->publicMetric($content, 'plays');
        $followers = $content->platformAccount?->follower_count;

        if ($views === null && $followers === null) {
            return null;
        }

        [$viewWeight, $followerWeight] = $this->resolveWeights($configuration, $content);

        $viewsAmount = $views?->amount;
        $followersAmount = $followers?->amount;

        $amount = round($viewWeight * ($viewsAmount ?? 0.0) + $followerWeight * ($followersAmount ?? 0.0));

        return [
            'amount' => $amount,
            'inputs' => [
                'view_weight' => $viewWeight,
                'follower_weight' => $followerWeight,
                'views' => ['amount' => $viewsAmount, 'included' => $viewsAmount !== null],
                'followers' => ['amount' => $followersAmount, 'included' => $followersAmount !== null],
            ],
        ];
    }

    private function publicMetric(ContentItem $content, string $metric): ?MetricValue
    {
        foreach ($content->public_metrics ?? [] as $value) {
            if ($value->metric === $metric) {
                return $value;
            }
        }

        return null;
    }

    /** @return array{0: float, 1: float} [view_weight, follower_weight] */
    private function resolveWeights(ReachConfiguration $configuration, ContentItem $content): array
    {
        $params = $configuration->params;
        $override = $params['platforms'][$content->platform->value] ?? [];

        $viewWeight = is_numeric($override['view_weight'] ?? null)
            ? (float) $override['view_weight']
            : (float) ($params['view_weight'] ?? 0.0);

        $followerWeight = is_numeric($override['follower_weight'] ?? null)
            ? (float) $override['follower_weight']
            : (float) ($params['follower_weight'] ?? 0.0);

        return [$viewWeight, $followerWeight];
    }
}
