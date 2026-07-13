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
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Analytics\Contracts\AnalyticsService;
use App\Platform\Analytics\RollupReader;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Step-4 RollupReader read paths (ADR-0010: dashboards and exports
 * consume rollups through this reader ONLY). NULL ⇒ unavailable — an
 * unmeasured aggregate must come back null, never zero (DP-001 / DEF
 * register); ESTIMATED tier labels pass through untouched.
 */
class SeedingRollupReaderTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private Product $product;

    private Campaign $campaign;

    private SeedingCampaign $run;

    private Shipment $shipment;

    private RollupReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = app(RollupReader::class);

        $client = Client::factory()->create();
        $this->brand = Brand::factory()->create(['client_id' => $client->id]);
        $this->product = Product::factory()->create(['brand_id' => $this->brand->id]);
        $this->campaign = Campaign::factory()->create(['brand_id' => $this->brand->id]);
        $this->run = SeedingCampaign::factory()->create([
            'brand_id' => $this->brand->id,
            'product_id' => $this->product->id,
            'campaign_id' => $this->campaign->id,
        ]);
    }

    /** Ship → post → measure → attribute a mention, then refresh. */
    private function seedResults(): void
    {
        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
        ]);

        $this->shipment = Shipment::factory()->create([
            'seeding_campaign_id' => $this->run->id,
            'creator_id' => $creator->id,
            'product_id' => $this->product->id,
            'status' => ShipmentStatus::Shipped,
            'shipped_at' => '2026-06-01 10:00:00',
            'product_value_at_ship' => new MetricValue(120.0, MetricTier::Confirmed),
        ]);

        $content = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'content_type' => ContentType::Reel,
            'published_at' => '2026-06-11 10:00:00',
        ]);
        app(ShipmentContentWriter::class)->link($this->shipment->id, $content);

        MetricSnapshot::create([
            'content_item_id' => $content->id,
            'captured_at' => '2026-06-12 09:00:00',
            'metrics' => [
                new MetricValue(500, MetricTier::Public, 'views'),
                new MetricValue(40, MetricTier::Public, 'likes'),
                new MetricValue(10, MetricTier::Public, 'comments'),
            ],
            'provenance' => new Provenance('SRC-apify-instagram-scraper', now()->toImmutable(), 'v1'),
        ]);

        Mention::factory()->create([
            'monitored_subject_id' => MonitoredSubject::factory()->create(['creator_id' => $creator->id])->id,
            'content_item_id' => $content->id,
            'story_id' => null,
            'campaign_id' => $this->campaign->id,
        ]);

        app(AnalyticsService::class)->refreshRollups();
    }

    public function test_seeding_by_product_filters_by_brand_and_product(): void
    {
        $this->seedResults();

        $rows = $this->reader->seedingByProduct('month');
        $this->assertCount(1, $rows);
        $this->assertSame($this->product->id, (int) $rows->first()->product_id);
        $this->assertSame(1, (int) $rows->first()->shipments);
        $this->assertSame(500.0, (float) $rows->first()->total_views);

        // Brand filter resolves through DIM-Product.
        $this->assertCount(1, $this->reader->seedingByProduct('month', brandId: $this->brand->id));
        $this->assertCount(0, $this->reader->seedingByProduct('month', brandId: Brand::factory()->create()->id));

        $this->assertCount(1, $this->reader->seedingByProduct('month', productId: $this->product->id));
        $this->assertCount(0, $this->reader->seedingByProduct('month', productId: Product::factory()->create()->id));

        // Unknown grain falls back to month — never an unvalidated string.
        $this->assertCount(1, $this->reader->seedingByProduct('bogus'));
    }

    public function test_seeding_product_slices_filter_by_slice_dimensions(): void
    {
        $this->seedResults();

        $slices = $this->reader->seedingProductSlices('month', productId: $this->product->id);
        $this->assertCount(1, $slices);
        $this->assertSame('INSTAGRAM', $slices->first()->platform);
        $this->assertSame('REEL', $slices->first()->content_type);
        $this->assertNull($slices->first()->country);
        $this->assertSame(500.0, (float) $slices->first()->total_views);

        $this->assertCount(1, $this->reader->seedingProductSlices('month', platform: 'INSTAGRAM'));
        $this->assertCount(0, $this->reader->seedingProductSlices('month', platform: 'TIKTOK'));
        $this->assertCount(1, $this->reader->seedingProductSlices('month', contentType: 'REEL'));
        $this->assertCount(0, $this->reader->seedingProductSlices('month', contentType: 'VIDEO'));
        // DIM-Geo is empty until Module 2: no country ever matches.
        $this->assertCount(0, $this->reader->seedingProductSlices('month', country: 'FR'));
    }

    public function test_seeding_by_shipment_lists_the_run_shipments(): void
    {
        $this->seedResults();

        $rows = $this->reader->seedingByShipment($this->run->id);
        $this->assertCount(1, $rows);
        $this->assertSame($this->shipment->id, (int) $rows->first()->shipment_id);
        $this->assertSame(1, (int) $rows->first()->posted);
        $this->assertSame(1, (int) $rows->first()->content_count);

        $this->assertCount(0, $this->reader->seedingByShipment(SeedingCampaign::factory()->create()->id));
    }

    public function test_seeding_campaign_totals_sum_one_run(): void
    {
        $this->seedResults();

        $totals = $this->reader->seedingCampaignTotals($this->run->id);
        $this->assertSame(1, $totals->shipments);
        $this->assertSame(1, $totals->posted_count);
        $this->assertSame(1, $totals->creators_reached);
        $this->assertSame(1, $totals->content_count);
        $this->assertSame(500.0, (float) $totals->total_views);
        $this->assertSame(50.0, (float) $totals->total_engagement);

        // Reach/EMV were never measured: NULL (unavailable), tier labels
        // pass through so the surface can still label the estimate class.
        $this->assertNull($totals->total_estimated_reach);
        $this->assertSame('ESTIMATED', $totals->total_estimated_reach_tier);
        $this->assertNull($totals->total_emv);
        $this->assertSame('ESTIMATED', $totals->total_emv_tier);
    }

    public function test_campaign_mention_totals_sum_one_campaign(): void
    {
        $this->seedResults();

        $totals = $this->reader->campaignMentionTotals($this->campaign->id);
        $this->assertSame(1, $totals->mention_count);
        $this->assertSame(1, $totals->content_count);
        $this->assertSame(500.0, (float) $totals->total_views);
        $this->assertSame(40.0, (float) $totals->total_likes);
        $this->assertSame(10.0, (float) $totals->total_comments);
        $this->assertSame(50.0, (float) $totals->total_engagement);
        $this->assertNull($totals->total_estimated_reach);
        $this->assertSame('ESTIMATED', $totals->total_estimated_reach_tier);
        $this->assertNull($totals->total_emv);
        $this->assertSame('ESTIMATED', $totals->total_emv_tier);
    }

    public function test_empty_rollups_read_as_unavailable_never_zero(): void
    {
        app(AnalyticsService::class)->refreshRollups();

        $this->assertCount(0, $this->reader->seedingByProduct('month'));
        $this->assertCount(0, $this->reader->seedingProductSlices('month'));
        $this->assertCount(0, $this->reader->seedingByShipment($this->run->id));

        $totals = $this->reader->seedingCampaignTotals($this->run->id);
        $this->assertNull($totals->shipments);
        $this->assertNull($totals->posted_count);
        $this->assertSame(0, $totals->creators_reached);
        $this->assertNull($totals->content_count);
        $this->assertNull($totals->total_views);
        $this->assertNull($totals->total_engagement);
        $this->assertNull($totals->total_estimated_reach);
        $this->assertNull($totals->total_estimated_reach_tier);
        $this->assertNull($totals->total_emv);
        $this->assertNull($totals->total_emv_tier);

        $mentionTotals = $this->reader->campaignMentionTotals($this->campaign->id);
        $this->assertNull($mentionTotals->mention_count);
        $this->assertNull($mentionTotals->content_count);
        $this->assertNull($mentionTotals->total_views);
        $this->assertNull($mentionTotals->total_engagement);
        $this->assertNull($mentionTotals->total_estimated_reach_tier);
        $this->assertNull($mentionTotals->total_emv_tier);
    }
}
