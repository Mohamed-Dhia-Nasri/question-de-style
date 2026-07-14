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
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Modules\Monitoring\Models\ReachResult;
use App\Platform\Analytics\Contracts\AnalyticsService;
use App\Platform\Analytics\RollupReader;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use App\Shared\ValueObjects\ReachEstimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task B4: stored reach (`reach_results`) must flow through BOTH the
 * fact_mention and fact_seeding_content loaders into their rollups,
 * mirroring the existing EMV LATERAL join (analytics-model §5). Mirrors
 * the mention+shipment content graph from CampaignResultsPanelTest.
 */
class ReachRollupTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private Product $product;

    private Campaign $campaign;

    private SeedingCampaign $run;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_stored_reach_flows_through_both_fact_loaders_into_the_rollups(): void
    {
        $this->seedRoles();

        $creator = Creator::factory()->create();
        $account = PlatformAccount::factory()->create([
            'creator_id' => $creator->id,
            'platform' => Platform::Instagram,
        ]);

        $shipment = Shipment::factory()->create([
            'seeding_campaign_id' => $this->run->id,
            'creator_id' => $creator->id,
            'product_id' => $this->product->id,
            'status' => ShipmentStatus::Shipped,
            'shipped_at' => '2026-06-01 10:00:00',
        ]);

        $content = ContentItem::factory()->create([
            'platform_account_id' => $account->id,
            'platform' => Platform::Instagram,
            'content_type' => ContentType::Reel,
            'published_at' => '2026-06-11 10:00:00',
        ]);
        app(ShipmentContentWriter::class)->link($shipment->id, $content);

        MetricSnapshot::create([
            'content_item_id' => $content->id,
            'captured_at' => '2026-06-12 09:00:00',
            'metrics' => collect(['views' => 500, 'likes' => 40, 'comments' => 10])
                ->map(fn (int $amount, string $metric) => new MetricValue($amount, MetricTier::Public, $metric))
                ->values()
                ->all(),
            'provenance' => new Provenance('SRC-apify-instagram-scraper', now()->toImmutable(), 'v1'),
        ]);

        Mention::factory()->create([
            'monitored_subject_id' => MonitoredSubject::factory()->create(['creator_id' => $creator->id])->id,
            'content_item_id' => $content->id,
            'story_id' => null,
            'campaign_id' => $this->campaign->id,
        ]);

        $config = ReachConfiguration::factory()->active()->create();
        ReachResult::query()->create([
            'content_item_id' => $content->id,
            'reach_configuration_id' => $config->id,
            'formula_version' => $config->formula_version,
            'value' => new ReachEstimate(1234.0, MetricTier::Estimated, 'qds-estimated-reach v'.$config->formula_version),
            'inputs' => ['views' => ['amount' => 1000, 'included' => true], 'followers' => ['amount' => 5000, 'included' => true]],
            'calculated_at' => now(),
        ]);

        app(AnalyticsService::class)->refreshRollups();

        $reader = app(RollupReader::class);

        // fact_mention -> rollup_mention_by_campaign
        $this->assertSame(1234.0, (float) $reader->campaignMentionTotals($this->campaign->id)->total_estimated_reach);
        $this->assertSame('ESTIMATED', $reader->campaignMentionTotals($this->campaign->id)->total_estimated_reach_tier);

        // fact_seeding_content -> rollup_seeding_by_creator_campaign
        $this->assertSame(1234.0, (float) $reader->seedingCampaignTotals($this->run->id)->total_estimated_reach);
    }
}
