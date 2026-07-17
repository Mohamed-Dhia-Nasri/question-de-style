<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Platform\Enrichment\Contracts\ReachEstimator;
use App\Platform\Enrichment\Metrics\DerivedMetricsService;
use App\Platform\Enrichment\Reach\DefaultReachEstimator;
use App\Shared\Enums\MetricTier;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\ReachEstimate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * DERIVED metrics (REQ-M1-005) and the reach boundary (REQ-M1-006).
 *
 * Doctrine under test: every rate/average/median is tier DERIVED — never
 * PUBLIC (DP-001, rule F7); missing inputs yield NULL (unavailable), never
 * zero; reach is NEVER derived from views (GL-PublicViews) and every
 * ReachEstimate must disclose its method.
 */
class MetricsAndReachTest extends TestCase
{
    use RefreshDatabase;

    private DerivedMetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = app(DerivedMetricsService::class);
    }

    /** @param array<string, float|int> $amounts */
    private function contentWithMetrics(array $amounts): ContentItem
    {
        $publicMetrics = [];

        foreach ($amounts as $metric => $amount) {
            $publicMetrics[] = new MetricValue((float) $amount, MetricTier::Public, $metric);
        }

        return ContentItem::factory()->create(['public_metrics' => $publicMetrics]);
    }

    private function followers(float $amount): MetricValue
    {
        return new MetricValue($amount, MetricTier::Public, 'followers');
    }

    // ── Engagement rate ─────────────────────────────────────────────────

    public function test_engagement_rate_sums_components_over_followers_and_is_derived(): void
    {
        $content = $this->contentWithMetrics(['likes' => 200, 'comments' => 50, 'shares' => 25, 'saves' => 25]);

        $rate = $this->metrics->engagementRate($content, $this->followers(10_000));

        $this->assertNotNull($rate);
        $this->assertEqualsWithDelta(0.03, $rate->amount, 1e-9);
        $this->assertSame(MetricTier::Derived, $rate->tier);
        $this->assertNotSame(MetricTier::Public, $rate->tier);
        $this->assertSame('engagement_rate', $rate->metric);
    }

    public function test_engagement_rate_excludes_unobserved_components_instead_of_zero_filling(): void
    {
        // Only likes observed — comments/shares/saves are UNOBSERVED and
        // must contribute nothing (missing is never zero).
        $content = $this->contentWithMetrics(['likes' => 100]);

        $rate = $this->metrics->engagementRate($content, $this->followers(1_000));

        $this->assertNotNull($rate);
        $this->assertEqualsWithDelta(0.1, $rate->amount, 1e-9);
    }

    public function test_engagement_rate_honors_configured_views_base(): void
    {
        config(['qds.enrichment.metrics.engagement_base' => 'views']);

        $content = $this->contentWithMetrics(['likes' => 100, 'comments' => 50, 'views' => 10_000]);

        $this->assertSame('views', $this->metrics->engagementBaseModel());

        // Divisor is the content's own views — no follower count needed.
        $rate = $this->metrics->engagementRate($content);

        $this->assertNotNull($rate);
        $this->assertEqualsWithDelta(0.015, $rate->amount, 1e-9);
        $this->assertSame(MetricTier::Derived, $rate->tier);
    }

    public function test_engagement_rate_is_null_when_follower_base_is_zero(): void
    {
        $content = $this->contentWithMetrics(['likes' => 100, 'comments' => 10]);

        $this->assertNull($this->metrics->engagementRate($content, $this->followers(0)));
    }

    public function test_engagement_rate_is_null_when_follower_count_is_missing(): void
    {
        $content = $this->contentWithMetrics(['likes' => 100, 'comments' => 10]);

        $this->assertNull($this->metrics->engagementRate($content, null));
    }

    public function test_engagement_rate_is_null_not_zero_without_any_engagement_components(): void
    {
        $content = $this->contentWithMetrics(['views' => 50_000]);

        // Unavailable, never a fabricated 0.0 rate.
        $this->assertNull($this->metrics->engagementRate($content, $this->followers(10_000)));
    }

    // ── View rate / comment rate ────────────────────────────────────────

    public function test_view_rate_is_views_over_followers_and_derived(): void
    {
        $content = $this->contentWithMetrics(['views' => 5_000, 'likes' => 10]);

        $rate = $this->metrics->viewRate($content, $this->followers(1_000));

        $this->assertNotNull($rate);
        $this->assertEqualsWithDelta(5.0, $rate->amount, 1e-9);
        $this->assertSame(MetricTier::Derived, $rate->tier);
        $this->assertSame('view_rate', $rate->metric);
    }

    public function test_view_rate_is_null_when_views_are_unobserved(): void
    {
        $content = $this->contentWithMetrics(['likes' => 500]);

        $this->assertNull($this->metrics->viewRate($content, $this->followers(1_000)));
    }

    public function test_comment_rate_is_comments_over_base_and_null_without_comments(): void
    {
        $content = $this->contentWithMetrics(['comments' => 40, 'likes' => 100]);

        $rate = $this->metrics->commentRate($content, $this->followers(2_000));

        $this->assertNotNull($rate);
        $this->assertEqualsWithDelta(0.02, $rate->amount, 1e-9);
        $this->assertSame(MetricTier::Derived, $rate->tier);
        $this->assertSame('comment_rate', $rate->metric);

        $withoutComments = $this->contentWithMetrics(['likes' => 100]);

        $this->assertNull($this->metrics->commentRate($withoutComments, $this->followers(2_000)));
    }

    // ── Average / median performance ────────────────────────────────────

    public function test_average_performance_is_null_for_empty_set_and_derived_otherwise(): void
    {
        $this->assertNull($this->metrics->averagePerformance([]));

        $average = $this->metrics->averagePerformance([10.0, 20.0, 30.0]);

        $this->assertNotNull($average);
        $this->assertEqualsWithDelta(20.0, $average->amount, 1e-9);
        $this->assertSame(MetricTier::Derived, $average->tier);
        $this->assertSame('average_performance', $average->metric);
    }

    public function test_median_performance_handles_odd_and_even_counts_and_empty_set(): void
    {
        $this->assertNull($this->metrics->medianPerformance([]));

        $odd = $this->metrics->medianPerformance([3.0, 1.0, 2.0]);

        $this->assertNotNull($odd);
        $this->assertEqualsWithDelta(2.0, $odd->amount, 1e-9);
        $this->assertSame(MetricTier::Derived, $odd->tier);
        $this->assertSame('median_performance', $odd->metric);

        $even = $this->metrics->medianPerformance([4.0, 1.0, 3.0, 2.0]);

        $this->assertNotNull($even);
        $this->assertEqualsWithDelta(2.5, $even->amount, 1e-9);
        $this->assertSame(MetricTier::Derived, $even->tier);
    }

    public function test_observed_amounts_skips_content_without_the_metric(): void
    {
        $items = [
            $this->contentWithMetrics(['views' => 1_000]),
            $this->contentWithMetrics(['likes' => 77]), // no views — contributes NOTHING
            $this->contentWithMetrics(['views' => 3_000]),
        ];

        $amounts = $this->metrics->observedAmounts($items, 'views');

        $this->assertSame([1_000.0, 3_000.0], $amounts);
        $this->assertNotContains(0.0, $amounts, 'Missing views must never be fabricated as zero.');
    }

    // ── Follower growth (ADR-0003 snapshot series) ──────────────────────

    public function test_follower_growth_is_last_minus_first_over_an_ordered_series(): void
    {
        $account = PlatformAccount::factory()->create();

        // Inserted out of chronological order on purpose — the series must
        // be ordered by captured_at, not by insertion.
        foreach ([['2026-06-20 12:00:00', 10_500.0], ['2026-06-10 12:00:00', 10_000.0], ['2026-06-30 12:00:00', 11_200.0]] as [$capturedAt, $followers]) {
            MetricSnapshot::factory()->create([
                'platform_account_id' => $account->id,
                'captured_at' => CarbonImmutable::parse($capturedAt),
                'metrics' => [
                    new MetricValue($followers, MetricTier::Public, 'followers'),
                    new MetricValue($followers * 3, MetricTier::Public, 'views'),
                ],
            ]);
        }

        $from = CarbonImmutable::parse('2026-06-01');
        $to = CarbonImmutable::parse('2026-07-01');

        $growth = $this->metrics->followerGrowth($account, $from, $to);

        $this->assertNotNull($growth);
        $this->assertEqualsWithDelta(1_200.0, $growth->amount, 1e-9);
        $this->assertSame(MetricTier::Derived, $growth->tier);
        $this->assertSame('follower_growth', $growth->metric);

        $series = $this->metrics->followerSeries($account, $from, $to);

        $this->assertSame([10_000.0, 10_500.0, 11_200.0], array_column($series, 'amount'));
        $this->assertTrue($series[0]['captured_at']->lessThan($series[1]['captured_at']));
        $this->assertTrue($series[1]['captured_at']->lessThan($series[2]['captured_at']));
    }

    public function test_follower_growth_is_null_with_fewer_than_two_observations(): void
    {
        $account = PlatformAccount::factory()->create();

        MetricSnapshot::factory()->create([
            'platform_account_id' => $account->id,
            'captured_at' => CarbonImmutable::parse('2026-06-15 12:00:00'),
            'metrics' => [new MetricValue(10_000.0, MetricTier::Public, 'followers')],
        ]);

        $growth = $this->metrics->followerGrowth(
            $account,
            CarbonImmutable::parse('2026-06-01'),
            CarbonImmutable::parse('2026-07-01'),
        );

        // One observation is not a window — unavailable, never a zero.
        $this->assertNull($growth);
    }

    public function test_posting_frequency_stays_unavailable(): void
    {
        $account = PlatformAccount::factory()->create();

        // ADR-0024 explicitly leaves posting frequency undecided — NULL
        // (unavailable), never invented.
        $this->assertNull($this->metrics->postingFrequency(
            $account,
            CarbonImmutable::parse('2026-06-01'),
            CarbonImmutable::parse('2026-07-01'),
        ));
    }

    private function trendContent(PlatformAccount $account, int $daysAgo, ?float $likes, ?float $comments): ContentItem
    {
        $metrics = [];

        if ($likes !== null) {
            $metrics[] = new MetricValue($likes, MetricTier::Public, 'likes');
        }

        if ($comments !== null) {
            $metrics[] = new MetricValue($comments, MetricTier::Public, 'comments');
        }

        return ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => $account->platform,
            'published_at' => CarbonImmutable::now()->subDays($daysAgo),
            'public_metrics' => $metrics,
        ]);
    }

    public function test_engagement_trend_compares_the_two_rolling_windows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-17 12:00:00'));

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);

        // Previous window (30–60 days ago): averages (80+120)/2 = 100.
        $this->trendContent($account, 45, likes: 70.0, comments: 10.0);
        $this->trendContent($account, 40, likes: 100.0, comments: 20.0);
        // Current window (0–30 days ago): averages (140+160)/2 = 150.
        $this->trendContent($account, 10, likes: 130.0, comments: 10.0);
        $this->trendContent($account, 5, likes: 150.0, comments: 10.0);
        // Excluded: neither likes nor comments observed (missing ≠ zero).
        $this->trendContent($account, 8, likes: null, comments: null);

        $trend = $this->metrics->engagementTrend($creator->fresh(), 30);

        $this->assertNotNull($trend);
        $this->assertEqualsWithDelta(150.0, $trend->currentAverage, 1e-9);
        $this->assertEqualsWithDelta(100.0, $trend->previousAverage, 1e-9);
        $this->assertSame(50, $trend->percentChange);
        $this->assertSame(2, $trend->currentCount);
        $this->assertSame(2, $trend->previousCount);
    }

    public function test_engagement_trend_counts_a_single_observed_component(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-17 12:00:00'));

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);

        $this->trendContent($account, 40, likes: 100.0, comments: null); // previous avg 100
        $this->trendContent($account, 10, likes: null, comments: 90.0);  // current avg 90

        $trend = $this->metrics->engagementTrend($creator->fresh(), 30);

        $this->assertNotNull($trend);
        $this->assertSame(-10, $trend->percentChange);
    }

    public function test_engagement_trend_is_unavailable_without_both_windows_or_with_zero_base(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-17 12:00:00'));

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);

        // Only current-window content → no comparison base.
        $this->trendContent($account, 10, likes: 100.0, comments: null);
        $this->assertNull($this->metrics->engagementTrend($creator->fresh(), 30));

        // Previous window exists but averages zero → division base is zero.
        $this->trendContent($account, 40, likes: 0.0, comments: null);
        $this->assertNull($this->metrics->engagementTrend($creator->fresh(), 30));
    }

    // ── Mention counts ──────────────────────────────────────────────────

    public function test_seeded_mention_count_counts_only_seeded_mentions_in_the_window(): void
    {
        Mention::factory()->seeded()->count(2)->create();
        Mention::factory()->paid()->create();
        Mention::factory()->create();
        Mention::factory()->seeded()->create(['created_at' => CarbonImmutable::parse('2026-01-01 00:00:00')]);

        $this->assertSame(3, $this->metrics->seededMentionCount());
        $this->assertSame(2, $this->metrics->seededMentionCount(
            CarbonImmutable::parse('2026-06-01'),
            CarbonImmutable::now()->addDay(),
        ));
    }

    public function test_campaign_and_brand_mention_counts_follow_the_campaign_link(): void
    {
        $brand = Brand::factory()->create();
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id]);
        $siblingCampaign = Campaign::factory()->create(['brand_id' => $brand->id]);
        $otherCampaign = Campaign::factory()->create();

        Mention::factory()->count(2)->create(['campaign_id' => $campaign->id]);
        Mention::factory()->create(['campaign_id' => $siblingCampaign->id]);
        Mention::factory()->create(['campaign_id' => $otherCampaign->id]);
        Mention::factory()->create(); // unattributed — counts nowhere

        $this->assertSame(2, $this->metrics->campaignMentionCount($campaign));
        $this->assertSame(1, $this->metrics->campaignMentionCount($siblingCampaign));
        $this->assertSame(3, $this->metrics->brandMentionCount($brand));
    }

    // ── Reach boundary (views are NEVER reach) ──────────────────────────

    public function test_reach_estimator_binding_is_unavailable_without_configuration_and_never_derives_reach_from_views(): void
    {
        // ADR-0022: the binding is the real, documented estimator — but
        // with no active reach configuration in this test, its only honest
        // answer is still UNAVAILABLE (never zero, never a raw view count).
        $estimator = app(ReachEstimator::class);

        $this->assertInstanceOf(DefaultReachEstimator::class, $estimator);

        // Even a huge PUBLIC view count must not be laundered into reach
        // (GL-PublicViews: views are not unique reach).
        $viral = $this->contentWithMetrics(['views' => 25_000_000, 'plays' => 25_000_000, 'likes' => 900_000]);

        $this->assertNull($estimator->estimate($viral));
        $this->assertNull(app(ReachEstimator::class)->estimate($this->contentWithMetrics(['views' => 100])));
    }

    public function test_reach_estimate_requires_a_disclosed_method(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReachEstimate(50_000.0, MetricTier::Estimated, '');
    }

    public function test_reach_estimate_rejects_public_tier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReachEstimate(50_000.0, MetricTier::Public, 'documented-model-v1');
    }

    public function test_reach_estimate_rejects_derived_tier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReachEstimate(50_000.0, MetricTier::Derived, 'documented-model-v1');
    }

    public function test_reach_estimate_accepts_estimated_tier_with_a_stated_method(): void
    {
        $estimate = new ReachEstimate(50_000.0, MetricTier::Estimated, 'documented-model-v1');

        // ESTIMATED, with its method disclosed — never presented as fact.
        $this->assertSame(MetricTier::Estimated, $estimate->tier);
        $this->assertSame('documented-model-v1', $estimate->method);
        $this->assertSame(
            ['amount' => 50_000.0, 'tier' => 'ESTIMATED', 'method' => 'documented-model-v1'],
            $estimate->toArray(),
        );
    }
}
