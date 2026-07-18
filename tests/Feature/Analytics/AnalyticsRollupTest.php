<?php

namespace Tests\Feature\Analytics;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Modules\Monitoring\Models\EmvResult;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Modules\Monitoring\Models\ReachResult;
use App\Platform\Analytics\Contracts\AnalyticsService;
use App\Platform\Analytics\NeonAnalyticsService;
use App\Platform\Analytics\RollupReader;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use App\Shared\ValueObjects\ReachEstimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SVC-Analytics (ADR-0010/ADR-0013): append-only fact loading from
 * own-DB snapshots, tier preservation (DP-001), DERIVED recomputation at
 * the rollup grain (never summed), incremental idempotent refresh, and
 * the personal-data exclusion rule for the star schema.
 */
class AnalyticsRollupTest extends TestCase
{
    use RefreshDatabase;

    private function provenance(): Provenance
    {
        return new Provenance('SRC-apify-instagram-profile-scraper', now()->toImmutable(), 'v1');
    }

    /** @param list<MetricValue> $metrics */
    private function accountSnapshot(PlatformAccount $account, array $metrics, string $capturedAt): MetricSnapshot
    {
        return MetricSnapshot::create([
            'platform_account_id' => $account->id,
            'captured_at' => $capturedAt,
            'metrics' => $metrics,
            'provenance' => $this->provenance(),
        ]);
    }

    /** @param list<MetricValue> $metrics */
    private function contentSnapshot(ContentItem $content, array $metrics, string $capturedAt): MetricSnapshot
    {
        return MetricSnapshot::create([
            'content_item_id' => $content->id,
            'captured_at' => $capturedAt,
            'metrics' => $metrics,
            'provenance' => $this->provenance(),
        ]);
    }

    public function test_fact_loading_is_incremental_idempotent_and_derived_rates_are_recomputed(): void
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
        ]);
        $content = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'published_at' => '2026-07-02 10:00:00',
        ]);

        $this->accountSnapshot($account, [new MetricValue(1000, MetricTier::Public, 'followers')], '2026-07-01 08:00:00');
        $this->accountSnapshot($account, [new MetricValue(1100, MetricTier::Public, 'followers')], '2026-07-03 08:00:00');
        $this->contentSnapshot($content, [
            new MetricValue(500, MetricTier::Public, 'views'),
            new MetricValue(40, MetricTier::Public, 'likes'),
            new MetricValue(10, MetricTier::Public, 'comments'),
        ], '2026-07-03 09:00:00');

        $service = app(AnalyticsService::class);
        $this->assertInstanceOf(NeonAnalyticsService::class, $service);

        $this->assertSame(count(NeonAnalyticsService::ROLLUPS), $service->refreshRollups());

        $this->assertSame(2, DB::table('fact_creator_account')->count());
        $this->assertSame(1, DB::table('fact_content_metric')->count());

        // Second refresh loads nothing new (idempotent watermarks).
        $service->refreshRollups();
        $this->assertSame(2, DB::table('fact_creator_account')->count());
        $this->assertSame(1, DB::table('fact_content_metric')->count());

        $bucket = DB::table('rollup_creator_by_period')
            ->where('grain', 'month')
            ->where('creator_id', $creator->id)
            ->first();

        // Followers = LAST snapshot in bucket; growth = last − first
        // (recomputed at the grain, ADR-0003 own-DB history).
        $this->assertSame(1100.0, (float) $bucket->followers);
        $this->assertSame(100.0, (float) $bucket->follower_growth);

        // DERIVED engagement rate is recomputed from summed PUBLIC
        // components at the grain — never summed (analytics-model rule 6).
        $this->assertEqualsWithDelta(50 / 1100, (float) $bucket->engagement_rate, 0.00001);
        $this->assertSame(500.0, (float) $bucket->avg_views);

        // No canonical posting-frequency formula → NULL, never zero.
        $this->assertNull($bucket->posting_frequency);
    }

    public function test_only_public_tier_metrics_enter_public_fact_measures(): void
    {
        $account = PlatformAccount::factory()->create(['creator_id' => Creator::factory()->create()->id]);
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);

        // An ESTIMATED value labelled "views" must never land in the
        // PUBLIC views measure (DP-001 tier preservation).
        $this->contentSnapshot($content, [
            new MetricValue(999999, MetricTier::Estimated, 'views'),
            new MetricValue(100, MetricTier::Public, 'likes'),
        ], '2026-07-03 09:00:00');

        app(AnalyticsService::class)->refreshRollups();

        $fact = DB::table('fact_content_metric')->first();
        $this->assertNull($fact->views);
        $this->assertSame(100.0, (float) $fact->likes);
    }

    public function test_facts_are_append_only_at_the_database_level(): void
    {
        $account = PlatformAccount::factory()->create(['creator_id' => Creator::factory()->create()->id]);
        $this->accountSnapshot($account, [new MetricValue(10, MetricTier::Public, 'followers')], '2026-07-01 08:00:00');

        app(AnalyticsService::class)->refreshRollups();

        $this->expectExceptionMessage('append-only');
        DB::table('fact_creator_account')->update(['followers' => 0]);
    }

    public function test_mention_rollup_computes_share_of_voice_and_labels_estimates(): void
    {
        $client = Client::factory()->create();
        $brandA = Brand::factory()->create(['client_id' => $client->id]);
        $brandB = Brand::factory()->create(['client_id' => $client->id]);
        $campaignA = Campaign::factory()->create(['brand_id' => $brandA->id]);
        $campaignB = Campaign::factory()->create(['brand_id' => $brandB->id]);

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);
        $subject = MonitoredSubject::factory()->create(['creator_id' => $creator->id]);

        $makeMention = function (Campaign $campaign) use ($account, $subject): void {
            $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);
            Mention::factory()->create([
                'monitored_subject_id' => $subject->id,
                'content_item_id' => $content->id,
                'story_id' => null,
                'campaign_id' => $campaign->id,
            ]);
        };

        $makeMention($campaignA);
        $makeMention($campaignA);
        $makeMention($campaignA);
        $makeMention($campaignB);

        app(AnalyticsService::class)->refreshRollups();

        $rows = DB::table('rollup_mention_by_brand')
            ->where('grain', 'month')
            ->orderBy('brand_id')
            ->get()
            ->keyBy('brand_id');

        $this->assertSame(3, (int) $rows[$brandA->id]->mention_count);
        $this->assertEqualsWithDelta(0.75, (float) $rows[$brandA->id]->share_of_voice, 0.00001);
        $this->assertEqualsWithDelta(0.25, (float) $rows[$brandB->id]->share_of_voice, 0.00001);

        // ESTIMATED aggregates stay NULL (unavailable) with their tier
        // label — never a fabricated zero (DP-001, DEF-003).
        $this->assertNull($rows[$brandA->id]->total_estimated_reach);
        $this->assertSame('ESTIMATED', $rows[$brandA->id]->total_estimated_reach_tier);
        $this->assertNull($rows[$brandA->id]->total_emv);
        $this->assertSame('ESTIMATED', $rows[$brandA->id]->total_emv_tier);
    }

    public function test_content_measures_are_not_double_counted_across_multiple_mentions(): void
    {
        // H4: the mentions unique index is (monitored_subject_id, content_item_id),
        // so ONE content item legitimately yields multiple mention rows when two
        // monitored subjects tie it to the same campaign. Each fact_mention row
        // carries the SAME content-level views/reach/EMV, so the rollup must
        // count those measures ONCE per content — not once per mention.
        $client = Client::factory()->create();
        $brand = Brand::factory()->create(['client_id' => $client->id]);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id]);

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);

        // One set of content-level measures on the single post.
        $this->contentSnapshot($content, [new MetricValue(500, MetricTier::Public, 'views')], '2026-07-03 09:00:00');

        $emvConfig = EmvConfiguration::factory()->active()->create();
        EmvResult::query()->create([
            'content_item_id' => $content->id,
            'emv_configuration_id' => $emvConfig->id,
            'formula_version' => $emvConfig->formula_version,
            'rate_card_version' => $emvConfig->rate_card_version,
            'currency' => 'EUR',
            'value' => new MetricValue(1234.0, MetricTier::Estimated, 'qds-emv v1'),
            'inputs' => [],
            'calculated_at' => now(),
        ]);

        $reachConfig = ReachConfiguration::factory()->active()->create();
        ReachResult::query()->create([
            'content_item_id' => $content->id,
            'reach_configuration_id' => $reachConfig->id,
            'formula_version' => $reachConfig->formula_version,
            'value' => new ReachEstimate(1000.0, MetricTier::Estimated, 'qds-estimated-reach v1'),
            'inputs' => [],
            'calculated_at' => now(),
        ]);

        // Two monitored subjects both mention the SAME content on the SAME campaign.
        foreach ([1, 2] as $ignored) {
            $subject = MonitoredSubject::factory()->create(['creator_id' => $creator->id]);
            Mention::factory()->create([
                'monitored_subject_id' => $subject->id,
                'content_item_id' => $content->id,
                'story_id' => null,
                'campaign_id' => $campaign->id,
            ]);
        }

        app(AnalyticsService::class)->refreshRollups();

        $brandRow = DB::table('rollup_mention_by_brand')
            ->where('grain', 'month')->where('brand_id', $brand->id)->first();
        $campaignRow = DB::table('rollup_mention_by_campaign')
            ->where('grain', 'month')->where('campaign_id', $campaign->id)->first();

        // Two mentions of the content...
        $this->assertSame(2, (int) $brandRow->mention_count);
        $this->assertSame(2, (int) $campaignRow->mention_count);
        $this->assertSame(1, (int) $campaignRow->content_count);

        // ...but the single post's audience/value is counted ONCE, not doubled.
        $this->assertSame(500.0, (float) $brandRow->total_views);
        $this->assertSame(1000.0, (float) $brandRow->total_estimated_reach);
        $this->assertSame(1234.0, (float) $brandRow->total_emv);
        $this->assertSame(500.0, (float) $campaignRow->total_views);
        $this->assertSame(1000.0, (float) $campaignRow->total_estimated_reach);
        $this->assertSame(1234.0, (float) $campaignRow->total_emv);
    }

    public function test_analytics_schema_contains_no_personal_data_columns(): void
    {
        $tables = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where(fn ($q) => $q
                ->where('table_name', 'like', 'fact\_%')
                ->orWhere('table_name', 'like', 'dim\_%'))
            ->pluck('column_name', 'table_name');

        $forbidden = ['email', 'phone', 'postal_address', 'address', 'notes', 'handle', 'bio'];

        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where(fn ($q) => $q
                ->where('table_name', 'like', 'fact\_%')
                ->orWhere('table_name', 'like', 'dim\_%'))
            ->pluck('column_name')
            ->unique();

        foreach ($forbidden as $column) {
            $this->assertFalse(
                $columns->contains($column),
                "Analytics schema must not carry personal-data column [{$column}].",
            );
        }

        $this->assertNotEmpty($tables);
    }

    public function test_seeding_structures_exist_and_stay_empty_until_p3(): void
    {
        // The P0 analytics foundation ships every canonical FACT-/ROLLUP-
        // structure; the seeding loaders activate in P3 (ENT-Shipment).
        foreach (['fact_shipment', 'fact_seeding_content'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }

        app(AnalyticsService::class)->refreshRollups();

        foreach ([
            'rollup_seeding_by_shipment',
            'rollup_seeding_by_creator_campaign',
            'rollup_seeding_by_product',
            'rollup_seeding_by_brand',
            'rollup_metric_by_geo',
        ] as $rollup) {
            $this->assertSame(0, DB::table($rollup)->count(), "{$rollup} must exist and be empty in P1.");
        }
    }

    /** @param array{brand: Brand, campaign: Campaign, account: PlatformAccount, creator: Creator, emvConfig: EmvConfiguration} $ctx */
    private function seedEmvMention(array $ctx, string $currency): void
    {
        $content = ContentItem::factory()->create(['platform_account_id' => $ctx['account']->id]);
        $this->contentSnapshot($content, [new MetricValue(100, MetricTier::Public, 'views')], '2026-07-03 09:00:00');
        EmvResult::query()->create([
            'content_item_id' => $content->id,
            'emv_configuration_id' => $ctx['emvConfig']->id,
            'formula_version' => $ctx['emvConfig']->formula_version,
            'rate_card_version' => $ctx['emvConfig']->rate_card_version,
            'currency' => $currency,
            'value' => new MetricValue(1000.0, MetricTier::Estimated, 'qds-emv v1'),
            'inputs' => [],
            'calculated_at' => now(),
        ]);
        $subject = MonitoredSubject::factory()->create(['creator_id' => $ctx['creator']->id]);
        Mention::factory()->create([
            'monitored_subject_id' => $subject->id,
            'content_item_id' => $content->id,
            'story_id' => null,
            'campaign_id' => $ctx['campaign']->id,
        ]);
    }

    /** @return array{brand: Brand, campaign: Campaign, account: PlatformAccount, creator: Creator, emvConfig: EmvConfiguration} */
    private function emvContext(): array
    {
        $client = Client::factory()->create();
        $brand = Brand::factory()->create(['client_id' => $client->id]);
        $creator = Creator::factory()->create();

        return [
            'brand' => $brand,
            'campaign' => Campaign::factory()->create(['brand_id' => $brand->id]),
            'account' => PlatformAccount::factory()->create(['creator_id' => $creator->id]),
            'creator' => $creator,
            'emvConfig' => EmvConfiguration::factory()->active()->create(),
        ];
    }

    public function test_creator_totals_expose_each_engagement_component_separately(): void
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);

        $this->contentSnapshot($content, [
            new MetricValue(500, MetricTier::Public, 'views'),
            new MetricValue(40, MetricTier::Public, 'likes'),
            new MetricValue(9, MetricTier::Public, 'comments'),
            new MetricValue(3, MetricTier::Public, 'shares'),
            new MetricValue(2, MetricTier::Public, 'saves'),
        ], '2026-07-03 09:00:00');

        app(AnalyticsService::class)->refreshRollups();

        $totals = app(RollupReader::class)->creatorTotals();

        $this->assertSame(40.0, (float) $totals->likes_sum);
        $this->assertSame(9.0, (float) $totals->comments_sum);
        $this->assertSame(3.0, (float) $totals->shares_sum);
        $this->assertSame(2.0, (float) $totals->saves_sum);
        // Engagement stays the sum of the four components (unchanged).
        $this->assertSame(54.0, (float) $totals->engagement_sum);
    }

    public function test_mixed_currency_emv_is_reported_as_unavailable_not_summed(): void
    {
        $ctx = $this->emvContext();
        $this->seedEmvMention($ctx, 'EUR');
        $this->seedEmvMention($ctx, 'USD');

        app(AnalyticsService::class)->refreshRollups();

        // Summing EUR + USD into one number is meaningless — the bucket must
        // report EMV as unavailable and carry no currency label (M24).
        $brandRow = DB::table('rollup_mention_by_brand')
            ->where('grain', 'month')->where('brand_id', $ctx['brand']->id)->first();

        $this->assertNull($brandRow->total_emv);
        $this->assertNull($brandRow->total_emv_currency);
    }

    public function test_single_currency_emv_carries_its_currency_label(): void
    {
        $ctx = $this->emvContext();
        $this->seedEmvMention($ctx, 'EUR');

        app(AnalyticsService::class)->refreshRollups();

        $brandRow = DB::table('rollup_mention_by_brand')
            ->where('grain', 'month')->where('brand_id', $ctx['brand']->id)->first();

        $this->assertSame('EUR', $brandRow->total_emv_currency);
        $this->assertSame(1000.0, (float) $brandRow->total_emv);

        // The reader surfaces the currency for the KPI card.
        $totals = app(RollupReader::class)->mentionTotals(null, null, $ctx['brand']->id);
        $this->assertSame('EUR', $totals->total_emv_currency);
    }
}
