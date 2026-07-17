<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Results\CampaignResultsPanel;
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
use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Modules\Monitoring\Models\EmvResult;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Analytics\Contracts\AnalyticsService;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Campaign results panel (REQ-M3-009, AC-M3-014/015): rollup-backed
 * totals with correct tier labels, display-time CPE/CPM (D4 — unavailable
 * on missing spend or a NULL/zero divisor, never zero or infinity), the
 * EMV model disclosure, and estimated-reach honesty (REQ-M1-006; never
 * fabricated). Results are reads —
 * crm.view suffices, CLIENT_VIEWER holds nothing.
 */
class CampaignResultsPanelTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private Product $product;

    private Campaign $campaign;

    private SeedingCampaign $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

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

    private function actingAsCrmStaff(): User
    {
        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    /**
     * Ship → post → measure → attribute a mention to the campaign, then
     * refresh — the SeedingRollupReaderTest graph (views 500, likes 40,
     * comments 10 by default ⇒ engagement 50).
     *
     * @param  array<string, int>  $metrics
     */
    private function seedResults(array $metrics = ['views' => 500, 'likes' => 40, 'comments' => 10]): void
    {
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
            'metrics' => collect($metrics)
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

        app(AnalyticsService::class)->refreshRollups();
    }

    public function test_the_campaign_detail_page_renders_the_results_panel(): void
    {
        $this->actingAsCrmStaff();

        $this->get('/crm/campaigns/'.$this->campaign->id)
            ->assertOk()
            ->assertSeeLivewire(CampaignResultsPanel::class);
    }

    public function test_client_viewers_cannot_reach_the_page_or_mount_the_panel(): void
    {
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $this->get('/crm/campaigns/'.$this->campaign->id)->assertForbidden();
        Livewire::test(CampaignResultsPanel::class, ['campaign' => $this->campaign])->assertForbidden();
    }

    public function test_a_crm_view_only_user_can_view_results(): void
    {
        // Results are reads: crm.view alone suffices — no crm.manage needed.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        Livewire::test(CampaignResultsPanel::class, ['campaign' => $this->campaign])->assertOk();
    }

    public function test_totals_render_with_their_tier_badges(): void
    {
        $this->actingAsCrmStaff();
        $this->seedResults();

        Livewire::test(CampaignResultsPanel::class, ['campaign' => $this->campaign])
            ->assertSee('500')            // views
            ->assertSee('40 / 10')        // likes / comments
            // Engagement sum pinned as the delimited KPI markup — a bare
            // assertSee('50') would also match the '500' views value
            // (deep-review L5).
            ->assertSeeHtml('>50</span>')
            ->assertSee('From platform')
            ->assertSee('Calculated')
            ->assertSee($this->run->name) // per-run totals row
            // Run name is a link back to its record (Stage B Task 8).
            ->assertSee(route('crm.seeding.show', $this->run), false);
    }

    public function test_cpe_and_cpm_compute_from_agency_spend_at_display_time(): void
    {
        $this->actingAsCrmStaff();
        $this->campaign->update(['spend' => new MetricValue(500.0, MetricTier::Confirmed, 'spend')]);
        $this->seedResults();

        // CPE = 500 / 50 = 10.00; CPM = 500 / (500 / 1000) = 1,000.00 — DERIVED.
        Livewire::test(CampaignResultsPanel::class, ['campaign' => $this->campaign])
            ->assertSee('10.00')
            ->assertSee('1,000.00')
            ->assertSee('Calculated');
    }

    public function test_cpe_and_cpm_are_unavailable_without_spend(): void
    {
        $this->actingAsCrmStaff();
        $this->seedResults();

        Livewire::test(CampaignResultsPanel::class, ['campaign' => $this->campaign])
            ->assertSee('No spend entered for this campaign yet');
    }

    public function test_cpe_is_unavailable_with_no_observed_engagement_never_zero_or_infinity(): void
    {
        $this->actingAsCrmStaff();
        $this->campaign->update(['spend' => new MetricValue(500.0, MetricTier::Confirmed, 'spend')]);
        // Views only: no engagement component was ever observed (DP-001).
        $this->seedResults(['views' => 500]);

        Livewire::test(CampaignResultsPanel::class, ['campaign' => $this->campaign])
            ->assertSee('No engagement observed yet')
            ->assertSee('1,000.00'); // CPM still computes from the observed views
    }

    public function test_reach_renders_unavailable_when_not_yet_computed(): void
    {
        $this->actingAsCrmStaff();
        $this->seedResults();

        // No active reach configuration/computed reach for this campaign:
        // the tile is honest about "not yet" (REQ-M1-006) — never DEF-003,
        // and the retired "True unique reach" placeholder tile is gone.
        Livewire::test(CampaignResultsPanel::class, ['campaign' => $this->campaign])
            ->assertSee('No estimated reach yet')
            ->assertSee('Settings → Reach')
            ->assertDontSee('DEF-003')
            ->assertDontSee('True unique reach');
    }

    public function test_emv_disclosure_cites_the_producing_model_not_the_active_one(): void
    {
        // Deep-review finding M4 (GL-EMV / AC-M1-011): the disclosure must
        // show the model + rates that PRODUCED the figures. Activating a
        // new rate card never re-stamps existing emv_results, so the
        // "active" configuration can diverge from the money on screen.
        $this->actingAsCrmStaff();

        // Nothing computed anywhere: the disclosure says so explicitly.
        Livewire::test(CampaignResultsPanel::class, ['campaign' => $this->campaign])
            ->assertSee('No EMV yet');

        // A figure produced under "Benchmark 2026"... Distinct currencies
        // (not the factory's default 'EUR' for both) so the disclosure's
        // currency assertion actually discriminates producing vs. active
        // (deep-review follow-up: a $producing->currency assertion is
        // inert when both configurations share the same hardcoded value).
        $producing = EmvConfiguration::factory()->create(['name' => 'Benchmark 2026', 'currency' => 'USD']);
        $this->makeEmvResult(ContentItem::factory()->create(), $producing);

        // ...stays disclosed as such even after a NEWER model becomes the
        // active one without any recalculation.
        $active = EmvConfiguration::factory()->active()->create(['name' => 'Benchmark 2027', 'currency' => 'GBP']);

        Livewire::test(CampaignResultsPanel::class, ['campaign' => $this->campaign])
            ->assertSee('Benchmark 2026')
            ->assertSee('USD')
            ->assertDontSee('GBP')
            ->assertDontSee('Benchmark 2027');

        // Once a figure IS produced under the newer model, both producing
        // models are disclosed with the span caveat.
        $this->makeEmvResult(ContentItem::factory()->create(), $active);

        Livewire::test(CampaignResultsPanel::class, ['campaign' => $this->campaign])
            ->assertSee('Benchmark 2026')
            ->assertSee('Benchmark 2027')
            ->assertSee('use 2 different rate cards');
    }

    private function makeEmvResult(ContentItem $content, EmvConfiguration $configuration): EmvResult
    {
        return EmvResult::create([
            'content_item_id' => $content->id,
            'emv_configuration_id' => $configuration->id,
            'formula_version' => $configuration->formula_version,
            'rate_card_version' => $configuration->rate_card_version,
            'currency' => $configuration->currency,
            'value' => new MetricValue(42.5, MetricTier::Estimated, 'emv'),
            'inputs' => [],
            'calculated_at' => now(),
        ]);
    }

    public function test_an_empty_campaign_shows_real_zero_counts_and_unavailable_sums(): void
    {
        $this->actingAsCrmStaff();

        // Counts of zero ARE measurements (rendered as 0); unmeasured sums
        // surface as "unavailable" — never a fabricated zero.
        Livewire::test(CampaignResultsPanel::class, ['campaign' => $this->campaign])
            ->assertSeeHtml('0</p>')
            ->assertSee('No observed views for this campaign')
            ->assertSee('unavailable');
    }
}
