<?php

namespace App\Platform\Enrichment\Metrics;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\MetricTier;
use App\Shared\ValueObjects\MetricValue;
use Carbon\CarbonImmutable;

/**
 * DERIVED metrics (REQ-M1-005, metrics catalog MET-*). Every rate/average/
 * median is tier DERIVED — never PUBLIC (rule F7, DP-001). Missing inputs
 * yield NULL (unavailable) — never zero, never fabricated; division by a
 * missing or zero base yields NULL.
 *
 * Canonical formulas implemented verbatim:
 *  - MET-EngagementRate: (likes + comments + shares + saves) / engagementBase,
 *    engagementBase = followers or views per the configured model
 *    (`qds.enrichment.metrics.engagement_base`, disclosed with the value).
 *    Components a platform does not report are UNOBSERVED and excluded
 *    from the sum (missing is never zero); the included components are
 *    disclosed via the accompanying formula description.
 *  - MET-ViewRate: views / followers.
 *  - MET-CommentRate: comments / engagementBase.
 *  - MET-AveragePerformance / MET-MedianPerformance: mean / median of a
 *    chosen metric across a content set.
 *
 * Follower growth (AC-M1-008/021) is reconstructed from ordered own-DB
 * MetricSnapshots (ADR-0003) as the difference across the window.
 *
 * Engagement trend (MET-EngagementTrend) is canonical per ADR-0024: mean
 * observed likes+comments per post, last N days vs the N days before, as
 * a whole signed percent (N is the per-tenant trend window, ADR-0025).
 *
 * NO canonical formula exists for posting frequency (ADR-0024 explicitly
 * leaves it undecided) — that boundary returns NULL (unavailable); do not
 * invent one here.
 */
class DerivedMetricsService
{
    private const ENGAGEMENT_COMPONENTS = ['likes', 'comments', 'shares', 'saves'];

    public function engagementRate(ContentItem $content, ?MetricValue $followerCount = null): ?MetricValue
    {
        $components = $this->observedComponents($content);

        if ($components === []) {
            return null;
        }

        $base = $this->engagementBase($content, $followerCount);

        if ($base === null || $base <= 0.0) {
            return null;
        }

        return new MetricValue(array_sum($components) / $base, MetricTier::Derived, 'engagement_rate');
    }

    public function viewRate(ContentItem $content, ?MetricValue $followerCount = null): ?MetricValue
    {
        $views = $this->publicAmount($content, 'views') ?? $this->publicAmount($content, 'plays');
        $followers = $followerCount?->amount;

        if ($views === null || $followers === null || $followers <= 0.0) {
            return null;
        }

        return new MetricValue($views / $followers, MetricTier::Derived, 'view_rate');
    }

    public function commentRate(ContentItem $content, ?MetricValue $followerCount = null): ?MetricValue
    {
        $comments = $this->publicAmount($content, 'comments');

        if ($comments === null) {
            return null;
        }

        $base = $this->engagementBase($content, $followerCount);

        if ($base === null || $base <= 0.0) {
            return null;
        }

        return new MetricValue($comments / $base, MetricTier::Derived, 'comment_rate');
    }

    /** @param list<float> $amounts */
    public function averagePerformance(array $amounts, string $metric = 'average_performance'): ?MetricValue
    {
        if ($amounts === []) {
            return null;
        }

        return new MetricValue(array_sum($amounts) / count($amounts), MetricTier::Derived, $metric);
    }

    /** @param list<float> $amounts */
    public function medianPerformance(array $amounts, string $metric = 'median_performance'): ?MetricValue
    {
        if ($amounts === []) {
            return null;
        }

        sort($amounts);
        $count = count($amounts);
        $middle = intdiv($count, 2);

        $median = $count % 2 === 1
            ? $amounts[$middle]
            : ($amounts[$middle - 1] + $amounts[$middle]) / 2;

        return new MetricValue($median, MetricTier::Derived, $metric);
    }

    /**
     * Observed amounts of one PUBLIC metric across a content set (input to
     * average/median views). Content without the metric contributes
     * NOTHING — missing is never zero.
     *
     * @param  iterable<ContentItem>  $contentItems
     * @return list<float>
     */
    public function observedAmounts(iterable $contentItems, string $metric): array
    {
        $amounts = [];

        foreach ($contentItems as $content) {
            $amount = $this->publicAmount($content, $metric);

            if ($amount !== null) {
                $amounts[] = $amount;
            }
        }

        return $amounts;
    }

    /**
     * Follower growth over a window, reconstructed from ordered account-
     * level snapshots (ADR-0003). Needs at least two observations; fewer
     * yields NULL (unavailable), never a fabricated zero.
     */
    public function followerGrowth(
        PlatformAccount $account,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $metric = 'followers',
    ): ?MetricValue {
        $series = $this->followerSeries($account, $from, $to, $metric);

        if (count($series) < 2) {
            return null;
        }

        $first = $series[0]['amount'];
        $last = $series[count($series) - 1]['amount'];

        return new MetricValue($last - $first, MetricTier::Derived, 'follower_growth');
    }

    /**
     * The ordered growth series itself (charting, AC-M1-021).
     *
     * @return list<array{captured_at: CarbonImmutable, amount: float}>
     */
    public function followerSeries(
        PlatformAccount $account,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $metric = 'followers',
    ): array {
        $series = [];

        $snapshots = MetricSnapshot::query()
            ->where('platform_account_id', $account->id)
            ->whereBetween('captured_at', [$from, $to])
            ->orderBy('captured_at')
            ->get();

        foreach ($snapshots as $snapshot) {
            foreach ($snapshot->metrics ?? [] as $value) {
                if ($value->metric === $metric) {
                    $series[] = [
                        'captured_at' => CarbonImmutable::parse($snapshot->captured_at),
                        'amount' => $value->amount,
                    ];

                    break;
                }
            }
        }

        return $series;
    }

    /**
     * NO canonical formula exists (FACT-CreatorAccount names the measure
     * without defining it) — unavailable until a doc amendment defines it.
     * Flagged missing formula; do not invent one here.
     */
    public function postingFrequency(PlatformAccount $account, CarbonImmutable $from, CarbonImmutable $to): ?MetricValue
    {
        return null;
    }

    /**
     * ADR-0024: rolling-window engagement trend for a creator across all
     * their platform accounts. Content with NEITHER likes nor comments
     * observed is excluded (missing is never zero); a single observed
     * component counts as-is. NULL when either window has no included
     * content or the previous average is zero — never a fabricated figure.
     */
    public function engagementTrend(Creator $creator, int $windowDays): ?EngagementTrend
    {
        $now = CarbonImmutable::now();
        $currentStart = $now->subDays($windowDays);
        $previousStart = $now->subDays($windowDays * 2);

        $items = ContentItem::query()
            ->whereIn('platform_account_id', $creator->platformAccounts()->select('id'))
            ->where('published_at', '>=', $previousStart)
            ->where('published_at', '<', $now)
            ->get();

        $current = [];
        $previous = [];

        foreach ($items as $item) {
            $engagement = $this->observedEngagement($item);

            if ($engagement === null || $item->published_at === null) {
                continue;
            }

            if ($item->published_at->greaterThanOrEqualTo($currentStart)) {
                $current[] = $engagement;
            } else {
                $previous[] = $engagement;
            }
        }

        if ($current === [] || $previous === []) {
            return null;
        }

        $currentAverage = array_sum($current) / count($current);
        $previousAverage = array_sum($previous) / count($previous);

        if ($previousAverage <= 0.0) {
            return null;
        }

        return new EngagementTrend(
            currentAverage: $currentAverage,
            previousAverage: $previousAverage,
            percentChange: (int) round(($currentAverage - $previousAverage) / $previousAverage * 100),
            currentCount: count($current),
            previousCount: count($previous),
        );
    }

    /**
     * Sum of the OBSERVED likes/comments of one item; null when neither is
     * observed (such an item contributes nothing — missing is never zero).
     */
    private function observedEngagement(ContentItem $content): ?float
    {
        $likes = $this->publicAmount($content, 'likes');
        $comments = $this->publicAmount($content, 'comments');

        if ($likes === null && $comments === null) {
            return null;
        }

        return ($likes ?? 0.0) + ($comments ?? 0.0);
    }

    /** Count of SEEDED-classified mentions in a period (seeded-post count). */
    public function seededMentionCount(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): int
    {
        return Mention::query()
            ->where('mention_type', MentionType::Seeded)
            ->when($from, static fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, static fn ($q) => $q->where('created_at', '<=', $to))
            ->count();
    }

    public function campaignMentionCount(Campaign $campaign, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): int
    {
        return Mention::query()
            ->where('campaign_id', $campaign->id)
            ->when($from, static fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, static fn ($q) => $q->where('created_at', '<=', $to))
            ->count();
    }

    public function brandMentionCount(Brand $brand, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): int
    {
        return Mention::query()
            ->whereIn('campaign_id', Campaign::query()->where('brand_id', $brand->id)->select('id'))
            ->when($from, static fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, static fn ($q) => $q->where('created_at', '<=', $to))
            ->count();
    }

    /** The configured engagement divisor: followers or views (disclosed). */
    public function engagementBaseModel(): string
    {
        $base = (string) config('qds.enrichment.metrics.engagement_base');

        return in_array($base, ['followers', 'views'], true) ? $base : 'followers';
    }

    /** @return list<float> */
    private function observedComponents(ContentItem $content): array
    {
        $amounts = [];

        foreach (self::ENGAGEMENT_COMPONENTS as $component) {
            $amount = $this->publicAmount($content, $component);

            if ($amount !== null) {
                $amounts[] = $amount;
            }
        }

        return $amounts;
    }

    private function engagementBase(ContentItem $content, ?MetricValue $followerCount): ?float
    {
        if ($this->engagementBaseModel() === 'views') {
            return $this->publicAmount($content, 'views') ?? $this->publicAmount($content, 'plays');
        }

        return $followerCount?->amount;
    }

    private function publicAmount(ContentItem $content, string $metric): ?float
    {
        foreach ($content->public_metrics ?? [] as $value) {
            if ($value->metric === $metric) {
                return $value->amount;
            }
        }

        return null;
    }
}
