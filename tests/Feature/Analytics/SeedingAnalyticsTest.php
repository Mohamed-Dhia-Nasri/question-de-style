<?php

namespace Tests\Feature\Analytics;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\CRM\Services\ShipmentContentWriter;
use App\Modules\Discovery\Contracts\CreatorGeography;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Modules\Monitoring\Models\EmvResult;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Analytics\Contracts\AnalyticsService;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FACT-Shipment / FACT-SeedingContent loaders and the Step-4 rollups
 * (REQ-M3-009/013, AC-M3-018/019). Covers the D2 append-only mechanics:
 * stamp-once facts past per-source watermarks, the gated FACT-Shipment
 * re-stamp (`posted` flips — analytics-model §6 step 2), conflict-skip on
 * re-links, and tier honesty (DP-001: reach NULL/unavailable per DEF-003,
 * EMV always ESTIMATED, no fabricated zeros).
 */
class SeedingAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Brand $brand;

    private Product $product;

    private Campaign $campaign;

    private SeedingCampaign $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create();
        $this->brand = Brand::factory()->create(['client_id' => $this->client->id]);
        $this->product = Product::factory()->create(['brand_id' => $this->brand->id]);
        $this->campaign = Campaign::factory()->create(['brand_id' => $this->brand->id]);
        $this->run = SeedingCampaign::factory()->create([
            'brand_id' => $this->brand->id,
            'product_id' => $this->product->id,
            'campaign_id' => $this->campaign->id,
        ]);
    }

    private function makeShipment(string $shippedAt = '2026-06-01 10:00:00', ?Creator $creator = null): Shipment
    {
        return Shipment::factory()->create([
            'seeding_campaign_id' => $this->run->id,
            'creator_id' => ($creator ?? Creator::factory()->create())->id,
            'product_id' => $this->product->id,
            'status' => ShipmentStatus::Shipped,
            'shipped_at' => $shippedAt,
            'quantity' => 2,
            'product_value_at_ship' => new MetricValue(120.0, MetricTier::Confirmed),
        ]);
    }

    private function makeContent(Shipment $shipment, Platform $platform = Platform::Instagram, ContentType $type = ContentType::Reel, string $publishedAt = '2026-06-11 10:00:00'): ContentItem
    {
        $account = PlatformAccount::factory()->create([
            'creator_id' => $shipment->creator_id,
            'platform' => $platform,
        ]);

        return ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => $platform,
            'content_type' => $type,
            'published_at' => $publishedAt,
        ]);
    }

    /** @param array<string, int> $metrics labelled PUBLIC amounts */
    private function snapshot(ContentItem $content, array $metrics, string $capturedAt): MetricSnapshot
    {
        return MetricSnapshot::create([
            'content_item_id' => $content->id,
            'captured_at' => $capturedAt,
            'metrics' => collect($metrics)
                ->map(fn (int $amount, string $metric) => new MetricValue($amount, MetricTier::Public, $metric))
                ->values()
                ->all(),
            'provenance' => new Provenance('SRC-apify-instagram-scraper', now()->toImmutable(), 'v1'),
        ]);
    }

    private function refresh(): void
    {
        app(AnalyticsService::class)->refreshRollups();
    }

    public function test_shipment_and_seeding_content_facts_carry_the_canonical_row_shape(): void
    {
        $shipment = $this->makeShipment();
        $content = $this->makeContent($shipment);
        app(ShipmentContentWriter::class)->link($shipment->id, $content);

        $this->snapshot($content, ['views' => 500, 'likes' => 40, 'comments' => 10], '2026-06-12 09:00:00');
        $this->snapshot($content, ['views' => 900, 'likes' => 70, 'comments' => 15, 'shares' => 5], '2026-06-20 09:00:00');

        EmvResult::create([
            'content_item_id' => $content->id,
            'emv_configuration_id' => EmvConfiguration::factory()->create()->id,
            'formula_version' => 'formula-v1',
            'rate_card_version' => 'rates-v1',
            'currency' => 'EUR',
            'value' => new MetricValue(42.5, MetricTier::Estimated, 'emv'),
            'inputs' => [],
            'calculated_at' => '2026-06-20 10:00:00',
        ]);

        $this->refresh();

        $fact = DB::table('fact_shipment')->sole();
        $this->assertSame('2026-06-01', $fact->date_key);
        $this->assertSame($shipment->id, (int) $fact->shipment_id);
        $this->assertSame($shipment->creator_id, (int) $fact->creator_id);
        $this->assertSame($this->product->id, (int) $fact->product_id);
        $this->assertSame($this->brand->id, (int) $fact->brand_id);
        $this->assertSame($this->client->id, (int) $fact->client_id);
        $this->assertSame($this->run->id, (int) $fact->seeding_campaign_id);
        $this->assertSame($this->campaign->id, (int) $fact->campaign_id);
        $this->assertSame(1, (int) $fact->shipped);
        $this->assertSame(1, (int) $fact->posted);
        $this->assertSame(2.0, (float) $fact->quantity);
        // product_value from the CONFIRMED MetricValue envelope amount.
        $this->assertSame(120.0, (float) $fact->product_value);
        // postedAt (earliest linked publish, 06-11) − shippedAt (06-01).
        $this->assertSame(10.0, (float) $fact->days_to_post);

        // One FACT-SeedingContent row per metric-snapshot bucket
        // (AC-M3-018: results tracked over time), both anchored to the
        // content's posting date (canon DIM-Date(postedAt)).
        $rows = DB::table('fact_seeding_content')->orderBy('metric_snapshot_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['2026-06-11', '2026-06-11'], $rows->pluck('date_key')->all());

        $latest = $rows->last();
        $this->assertSame($shipment->id, (int) $latest->shipment_id);
        $this->assertSame($content->id, (int) $latest->content_item_id);
        $this->assertSame('INSTAGRAM', $latest->platform);
        $this->assertSame('REEL', $latest->content_type);
        $this->assertSame(900.0, (float) $latest->views);
        $this->assertSame(70.0, (float) $latest->likes);
        $this->assertSame(15.0, (float) $latest->comments);
        $this->assertSame(5.0, (float) $latest->shares);
        $this->assertNull($latest->saves);

        // Reach is DEF-003 unavailable — NULL value AND NULL tier, never
        // zero; EMV rides the latest emv_results row, always ESTIMATED.
        $this->assertNull($latest->estimated_reach);
        $this->assertNull($latest->estimated_reach_tier);
        $this->assertSame(42.5, (float) $latest->emv);
        $this->assertSame('ESTIMATED', $latest->emv_tier);
    }

    public function test_loading_is_idempotent_and_advances_the_watermarks(): void
    {
        $shipment = $this->makeShipment();
        $content = $this->makeContent($shipment);
        app(ShipmentContentWriter::class)->link($shipment->id, $content);
        $this->snapshot($content, ['views' => 500], '2026-06-12 09:00:00');

        $this->refresh();

        $this->assertSame(1, DB::table('fact_shipment')->count());
        $this->assertSame(1, DB::table('fact_seeding_content')->count());

        $watermarks = DB::table('analytics_watermarks')->pluck('last_id', 'source');
        $this->assertGreaterThan(0, (int) $watermarks['fact_shipment']);
        $this->assertSame((int) MetricSnapshot::query()->max('id'), (int) $watermarks['fact_seeding_content']);
        $this->assertSame(
            (int) DB::table('shipment_resulting_content')->max('id'),
            (int) $watermarks['fact_seeding_content_link'],
        );

        // Second refresh: nothing new to load, facts unchanged.
        $this->refresh();
        $this->assertSame(1, DB::table('fact_shipment')->count());
        $this->assertSame(1, DB::table('fact_seeding_content')->count());
        $this->assertSame(1, (int) DB::table('fact_shipment')->sole()->posted);

        // A later snapshot adds exactly one row past the watermark.
        $this->snapshot($content, ['views' => 900], '2026-06-20 09:00:00');
        $this->refresh();
        $this->assertSame(2, DB::table('fact_seeding_content')->count());
    }

    public function test_posted_flip_rematerializes_the_shipment_fact(): void
    {
        $shipment = $this->makeShipment();

        // Dispatched but not posted: the fact row stamps posted = 0 (a real
        // measurement — the creator has not posted), days_to_post NULL.
        $this->refresh();
        $fact = DB::table('fact_shipment')->sole();
        $this->assertSame(0, (int) $fact->posted);
        $this->assertNull($fact->days_to_post);

        $productBucket = DB::table('rollup_seeding_by_product')
            ->where('grain', 'month')->where('product_id', $this->product->id)->sole();
        $this->assertSame(0, (int) $productBucket->posted_count);
        $this->assertSame(0.0, (float) $productBucket->post_rate);

        // Content matching links a reel → ENT-Shipment mutates (posted
        // flips). The loader re-stamps the SAME fact row via the gated
        // delete + fresh insert (D2) — analytics-model §6 step 2:
        // "FACT-Shipment.posted flips; days_to_post computed".
        $content = $this->makeContent($shipment);
        app(ShipmentContentWriter::class)->link($shipment->id, $content);

        $this->refresh();

        $fact = DB::table('fact_shipment')->sole();
        $this->assertSame(1, (int) $fact->posted);
        $this->assertSame(10.0, (float) $fact->days_to_post);

        $productBucket = DB::table('rollup_seeding_by_product')
            ->where('grain', 'month')->where('product_id', $this->product->id)->sole();
        $this->assertSame(1, (int) $productBucket->posted_count);
        $this->assertSame(1.0, (float) $productBucket->post_rate);
    }

    public function test_late_links_backfill_and_relinks_conflict_skip(): void
    {
        $shipment = $this->makeShipment();
        $content = $this->makeContent($shipment);
        $this->snapshot($content, ['views' => 500], '2026-06-12 09:00:00');

        // Content and snapshot exist but are not linked yet: no rows.
        $this->refresh();
        $this->assertSame(0, DB::table('fact_seeding_content')->count());

        // A late link (REQ-M3-008 matching) backfills the content's
        // EXISTING snapshots through the link watermark.
        $writer = app(ShipmentContentWriter::class);
        $writer->link($shipment->id, $content);
        $this->refresh();
        $this->assertSame(1, DB::table('fact_seeding_content')->count());

        // Unlink leaves the accumulated facts in place (append-only);
        // re-linking re-scans the old snapshots and ON CONFLICT DO NOTHING
        // skips the rows that already exist — no duplicates, no failure.
        $writer->unlink($shipment->fresh(), $content);
        $this->refresh();
        $writer->link($shipment->id, $content);
        $this->refresh();
        $this->assertSame(1, DB::table('fact_seeding_content')->count());
    }

    public function test_fact_shipment_updates_stay_refused(): void
    {
        $this->makeShipment();
        $this->refresh();

        // The gated exception is DELETE-only: UPDATE is never permitted.
        $this->expectExceptionMessage('append-only');
        DB::table('fact_shipment')->update(['posted' => 1]);
    }

    public function test_fact_shipment_deletes_stay_refused_outside_the_loader_gate(): void
    {
        $this->makeShipment();
        $this->refresh();

        // DELETE passes only while the transaction-local loader gate is on
        // — the single sanctioned re-stamp path (spec D2); the loader left
        // it off, so a direct delete still raises.
        $this->expectExceptionMessage('append-only');
        DB::table('fact_shipment')->delete();
    }

    public function test_mention_by_campaign_rollup_aggregates_and_never_fabricates_engagement(): void
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);
        $subject = MonitoredSubject::factory()->create(['creator_id' => $creator->id]);

        $makeMention = function (?Campaign $campaign, ?array $metrics) use ($account, $subject): void {
            $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);
            if ($metrics !== null) {
                $this->snapshot($content, $metrics, '2026-06-12 09:00:00');
            }
            Mention::factory()->create([
                'monitored_subject_id' => $subject->id,
                'content_item_id' => $content->id,
                'story_id' => null,
                'campaign_id' => $campaign?->id,
            ]);
        };

        $makeMention($this->campaign, ['views' => 500, 'likes' => 40, 'comments' => 10]);
        $makeMention($this->campaign, ['views' => 1000, 'likes' => 60]);
        $makeMention(null, ['views' => 9999]); // unattributed — excluded

        // A second campaign whose only mention carries NO observed metric
        // components: engagement must surface NULL (unavailable), never a
        // fabricated zero (DP-001).
        $bare = Campaign::factory()->create(['brand_id' => $this->brand->id]);
        $makeMention($bare, null);

        $this->refresh();

        $row = DB::table('rollup_mention_by_campaign')
            ->where('grain', 'month')->where('campaign_id', $this->campaign->id)->sole();
        $this->assertSame(2, (int) $row->mention_count);
        $this->assertSame(2, (int) $row->content_count);
        $this->assertSame(1500.0, (float) $row->total_views);
        $this->assertSame(100.0, (float) $row->total_likes);
        $this->assertSame(10.0, (float) $row->total_comments);
        $this->assertSame(110.0, (float) $row->total_engagement);
        $this->assertNull($row->total_estimated_reach);
        $this->assertSame('ESTIMATED', $row->total_estimated_reach_tier);
        $this->assertNull($row->total_emv);
        $this->assertSame('ESTIMATED', $row->total_emv_tier);

        $bareRow = DB::table('rollup_mention_by_campaign')
            ->where('grain', 'month')->where('campaign_id', $bare->id)->sole();
        $this->assertSame(1, (int) $bareRow->mention_count);
        $this->assertNull($bareRow->total_views);
        $this->assertNull($bareRow->total_engagement);

        $this->assertSame(
            0,
            DB::table('rollup_mention_by_campaign')->whereNull('campaign_id')->count(),
            'Unattributed mentions must not produce a campaign bucket.',
        );
    }

    public function test_product_rollup_recomputes_post_rate_and_slice_rollup_slices_content(): void
    {
        // Two creators shipped; only one posts (post_rate = 0.5).
        $posting = $this->makeShipment();
        $this->makeShipment('2026-06-02 10:00:00');

        $reel = $this->makeContent($posting, Platform::Instagram, ContentType::Reel);
        app(ShipmentContentWriter::class)->link($posting->id, $reel);
        $this->snapshot($reel, ['views' => 100, 'likes' => 8, 'comments' => 2], '2026-06-12 09:00:00');

        $video = $this->makeContent($posting, Platform::TikTok, ContentType::Video, '2026-06-13 10:00:00');
        app(ShipmentContentWriter::class)->link($posting->id, $video);
        $this->snapshot($video, ['views' => 700, 'likes' => 50], '2026-06-14 09:00:00');

        $this->refresh();

        $bucket = DB::table('rollup_seeding_by_product')
            ->where('grain', 'month')->where('product_id', $this->product->id)->sole();
        $this->assertSame(2, (int) $bucket->shipments);
        $this->assertSame(1, (int) $bucket->posted_count);
        $this->assertSame(0.5, (float) $bucket->post_rate);
        $this->assertSame(2, (int) $bucket->creators_reached);
        $this->assertSame(2, (int) $bucket->content_count);
        $this->assertSame(800.0, (float) $bucket->total_views);
        $this->assertSame(60.0, (float) $bucket->total_engagement);
        $this->assertNull($bucket->total_estimated_reach);
        $this->assertSame('ESTIMATED', $bucket->total_estimated_reach_tier);

        // The slice companion splits the content measures by platform /
        // content type; country stays NULL until DIM-Geo ships (Module 2).
        $slices = DB::table('rollup_seeding_by_product_slice')
            ->where('grain', 'month')->where('product_id', $this->product->id)
            ->orderBy('platform')
            ->get();
        $this->assertCount(2, $slices);

        [$instagram, $tiktok] = $slices;
        $this->assertSame(['INSTAGRAM', 'REEL'], [$instagram->platform, $instagram->content_type]);
        $this->assertSame(100.0, (float) $instagram->total_views);
        $this->assertSame(10.0, (float) $instagram->total_engagement);
        $this->assertSame(1, (int) $instagram->creators_reached);
        $this->assertNull($instagram->country);

        $this->assertSame(['TIKTOK', 'VIDEO'], [$tiktok->platform, $tiktok->content_type]);
        $this->assertSame(700.0, (float) $tiktok->total_views);
        $this->assertNull($tiktok->total_emv);
        $this->assertSame('ESTIMATED', $tiktok->total_emv_tier);
    }

    public function test_late_campaign_attribution_restamps_the_mention_fact(): void
    {
        // Deep-review finding C2: attribution ALWAYS post-dates detection
        // (XMC-002 sets mentions.campaign_id on an existing row), so a
        // stamp-once fact_mention would exclude every attributed mention
        // from ROLLUP-MentionByCampaign forever. The loader re-stamps
        // mutated mentions through its own gated restamp.
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create(['creator_id' => $creator->id]);
        $subject = MonitoredSubject::factory()->create(['creator_id' => $creator->id]);
        $content = ContentItem::factory()->create(['platform_account_id' => $account->id]);
        $this->snapshot($content, ['views' => 500, 'likes' => 40], '2026-06-12 09:00:00');
        $mention = Mention::factory()->create([
            'monitored_subject_id' => $subject->id,
            'content_item_id' => $content->id,
            'story_id' => null,
            'campaign_id' => null,
        ]);

        // Fact-loaded while unattributed: no campaign bucket exists.
        $this->refresh();
        $this->assertNull(DB::table('fact_mention')->sole()->campaign_id);
        $this->assertSame(0, DB::table('rollup_mention_by_campaign')->count());

        // Matching attributes the mention (the XMC-002 recorder is the
        // production writer — its semantics have their own suite; the
        // loader only cares that campaign_id and updated_at moved).
        $mention->forceFill(['campaign_id' => $this->campaign->id])->save();

        $this->refresh();

        $fact = DB::table('fact_mention')->sole();
        $this->assertSame($this->campaign->id, (int) $fact->campaign_id);
        $this->assertSame($this->brand->id, (int) $fact->brand_id);

        $row = DB::table('rollup_mention_by_campaign')
            ->where('grain', 'month')->where('campaign_id', $this->campaign->id)->sole();
        $this->assertSame(1, (int) $row->mention_count);
        $this->assertSame(500.0, (float) $row->total_views);
    }

    public function test_restamp_gates_are_per_table(): void
    {
        // The two loader gates must not loosen each other: the mention gate
        // opens fact_mention only, the shipment gate fact_shipment only.
        $shipment = $this->makeShipment();
        $content = $this->makeContent($shipment);
        $subject = MonitoredSubject::factory()->create(['creator_id' => $shipment->creator_id]);
        Mention::factory()->create([
            'monitored_subject_id' => $subject->id,
            'content_item_id' => $content->id,
            'story_id' => null,
        ]);
        $this->refresh();

        try {
            DB::transaction(function (): void {
                DB::statement("SELECT set_config('qds.analytics_mention_restamp', 'on', true)");
                DB::table('fact_shipment')->delete();
            });
            $this->fail('fact_shipment DELETE must stay refused under the mention gate.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::transaction(function (): void {
                DB::statement("SELECT set_config('qds.analytics_shipment_restamp', 'on', true)");
                DB::table('fact_mention')->delete();
            });
            $this->fail('fact_mention DELETE must stay refused under the shipment gate.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_content_linked_to_two_shipments_counts_once_in_product_rollups(): void
    {
        // Deep-review finding M2: shipment_resulting_content is many-to-many
        // — one reel linked to TWO shipments of the same product must count
        // once per product bucket, not once per link.
        $creator = Creator::factory()->create();
        $first = $this->makeShipment('2026-06-01 10:00:00', $creator);
        $second = $this->makeShipment('2026-06-02 10:00:00', $creator);

        $reel = $this->makeContent($first);
        $writer = app(ShipmentContentWriter::class);
        $writer->link($first->id, $reel);
        $writer->link($second->id, $reel);
        $this->snapshot($reel, ['views' => 100, 'likes' => 8, 'comments' => 2], '2026-06-12 09:00:00');

        $this->refresh();

        $bucket = DB::table('rollup_seeding_by_product')
            ->where('grain', 'month')->where('product_id', $this->product->id)->sole();
        $this->assertSame(1, (int) $bucket->content_count);
        $this->assertSame(100.0, (float) $bucket->total_views);
        $this->assertSame(10.0, (float) $bucket->total_engagement);

        $creatorRow = DB::table('rollup_seeding_by_creator_campaign')
            ->where('creator_id', $creator->id)->where('seeding_campaign_id', $this->run->id)->sole();
        $this->assertSame(1, (int) $creatorRow->content_count);
        $this->assertSame(100.0, (float) $creatorRow->views);
        $this->assertSame(10.0, (float) $creatorRow->engagement);

        $slice = DB::table('rollup_seeding_by_product_slice')
            ->where('grain', 'month')->where('product_id', $this->product->id)->sole();
        $this->assertSame(1, (int) $slice->content_count);
        $this->assertSame(100.0, (float) $slice->total_views);

        $brandRow = DB::table('rollup_seeding_by_brand')
            ->where('grain', 'month')->where('brand_id', $this->brand->id)->sole();
        $this->assertSame(1, (int) $brandRow->content_count);
        $this->assertSame(100.0, (float) $brandRow->total_views);
    }

    public function test_days_to_post_is_null_when_content_predates_the_dispatch(): void
    {
        // Deep-review GAP-4: an operator can manually link content published
        // BEFORE the shipment went out; the shipped→post interval is then
        // unmeasurable and must load NULL — a negative value would poison
        // every days-to-post average.
        $shipment = $this->makeShipment('2026-06-10 10:00:00');
        $earlier = $this->makeContent($shipment, Platform::Instagram, ContentType::Reel, '2026-06-01 08:00:00');
        app(ShipmentContentWriter::class)->link($shipment->id, $earlier);

        $this->refresh();

        $fact = DB::table('fact_shipment')->sole();
        $this->assertSame(1, (int) $fact->posted);
        $this->assertNull($fact->days_to_post);
    }

    public function test_operator_assigned_geography_lights_up_the_country_slice(): void
    {
        // ADR-0018: an operator geography assignment feeds DIM-Geo on the
        // next refresh, so the product slice gains a real country — and a
        // cleared assignment stops slicing (dims are lookup rows, not
        // append-only facts).
        $shipment = $this->makeShipment();
        $reel = $this->makeContent($shipment);
        app(ShipmentContentWriter::class)->link($shipment->id, $reel);
        $this->snapshot($reel, ['views' => 100], '2026-06-12 09:00:00');

        $creator = Creator::query()->findOrFail($shipment->creator_id);
        app(CreatorGeography::class)->assign($creator, 'DE', 'Bavaria', 'Munich');

        $this->refresh();

        $dim = DB::table('dim_geo')->where('creator_id', $creator->id)->sole();
        $this->assertSame('DE', $dim->country_code);
        $this->assertSame('Munich', $dim->city);
        $this->assertSame('HIGH', $dim->confidence_level);
        $this->assertSame('HUMAN_REVIEWED', $dim->verification_status);

        $slice = DB::table('rollup_seeding_by_product_slice')
            ->where('grain', 'month')->where('product_id', $this->product->id)->sole();
        $this->assertSame('DE', $slice->country);

        // Withdrawal propagates: the dim row is removed and the slice
        // reverts to unavailable (NULL — never a fabricated country).
        app(CreatorGeography::class)->clear($creator);
        $this->refresh();

        $this->assertDatabaseMissing('dim_geo', ['creator_id' => $creator->id]);
        $slice = DB::table('rollup_seeding_by_product_slice')
            ->where('grain', 'month')->where('product_id', $this->product->id)->sole();
        $this->assertNull($slice->country);
    }

    public function test_seeding_engagement_is_null_when_no_component_observed(): void
    {
        // Deep-review finding H1: a run whose content recorded views but no
        // engagement component must surface NULL engagement (unavailable),
        // never a fabricated zero — matching ROLLUP-MentionByCampaign and
        // the per-creator table on the same panel (DP-001).
        $shipment = $this->makeShipment();
        $reel = $this->makeContent($shipment);
        app(ShipmentContentWriter::class)->link($shipment->id, $reel);
        $this->snapshot($reel, ['views' => 100], '2026-06-12 09:00:00');

        $this->refresh();

        $bucket = DB::table('rollup_seeding_by_product')
            ->where('grain', 'month')->where('product_id', $this->product->id)->sole();
        $this->assertSame(100.0, (float) $bucket->total_views);
        $this->assertNull($bucket->total_engagement);

        $creatorRow = DB::table('rollup_seeding_by_creator_campaign')
            ->where('seeding_campaign_id', $this->run->id)->sole();
        $this->assertNull($creatorRow->engagement);

        $slice = DB::table('rollup_seeding_by_product_slice')
            ->where('grain', 'month')->where('product_id', $this->product->id)->sole();
        $this->assertNull($slice->total_engagement);
    }
}
