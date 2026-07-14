<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Results\SeedingResultsPanel;
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
use App\Modules\Monitoring\Models\MetricSnapshot;
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
 * Seeding-run results panel (REQ-M3-009, AC-M3-014/015/018): run totals,
 * per-creator and per-shipment rollup rows with correct tier labels,
 * display-time CPE/CPM (D4), the EMV model disclosure, and estimated-reach
 * honesty (REQ-M1-006; never fabricated). Results are reads — crm.view
 * suffices, CLIENT_VIEWER holds nothing.
 */
class SeedingResultsPanelTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private Product $product;

    private SeedingCampaign $run;

    private Creator $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        $client = Client::factory()->create();
        $this->brand = Brand::factory()->create(['client_id' => $client->id]);
        $this->product = Product::factory()->create(['brand_id' => $this->brand->id]);
        $this->run = SeedingCampaign::factory()->create([
            'brand_id' => $this->brand->id,
            'product_id' => $this->product->id,
            'campaign_id' => Campaign::factory()->create(['brand_id' => $this->brand->id])->id,
        ]);
        $this->creator = Creator::factory()->create(['display_name' => 'Nova Lang']);
    }

    private function actingAsCrmStaff(): User
    {
        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    /**
     * Ship → post (+10 days) → measure, then refresh — views 500, likes
     * 40, comments 10 by default ⇒ engagement 50.
     *
     * @param  array<string, int>  $metrics
     */
    private function seedResults(array $metrics = ['views' => 500, 'likes' => 40, 'comments' => 10]): void
    {
        $account = PlatformAccount::factory()->create([
            'creator_id' => $this->creator->id,
            'platform' => Platform::Instagram,
        ]);

        $shipment = Shipment::factory()->create([
            'seeding_campaign_id' => $this->run->id,
            'creator_id' => $this->creator->id,
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

        app(AnalyticsService::class)->refreshRollups();
    }

    public function test_the_seeding_detail_page_renders_the_results_panel(): void
    {
        $this->actingAsCrmStaff();

        $this->get('/crm/seeding/'.$this->run->id)
            ->assertOk()
            ->assertSeeLivewire(SeedingResultsPanel::class);
    }

    public function test_client_viewers_cannot_reach_the_page_or_mount_the_panel(): void
    {
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $this->get('/crm/seeding/'.$this->run->id)->assertForbidden();
        Livewire::test(SeedingResultsPanel::class, ['seedingCampaign' => $this->run])->assertForbidden();
    }

    public function test_a_crm_view_only_user_can_view_results(): void
    {
        // Results are reads: crm.view alone suffices — no crm.manage needed.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        Livewire::test(SeedingResultsPanel::class, ['seedingCampaign' => $this->run])->assertOk();
    }

    public function test_run_totals_and_per_creator_and_per_shipment_rows_render(): void
    {
        $this->actingAsCrmStaff();
        $this->seedResults();

        // AC-M3-018: what was sent, did they post, when (10.0 days to
        // post), how did it perform — with tier badges (DP-001).
        Livewire::test(SeedingResultsPanel::class, ['seedingCampaign' => $this->run])
            ->assertSee('1 / 1')       // shipments / posted
            ->assertSee('Nova Lang')   // per-creator + per-shipment rows
            ->assertSee('Posted')
            ->assertSee('10.0')        // days to post, DERIVED at the loader
            ->assertSee('500')
            ->assertSee('PUBLIC')
            ->assertSee('DERIVED')
            ->assertSee($this->product->name);
    }

    public function test_cpe_and_cpm_compute_from_the_run_spend_at_display_time(): void
    {
        $this->actingAsCrmStaff();
        $this->run->update(['spend' => new MetricValue(100.0, MetricTier::Confirmed, 'spend')]);
        $this->seedResults();

        // CPE = 100 / 50 = 2.00; CPM = 100 / (500 / 1000) = 200.00 — DERIVED.
        Livewire::test(SeedingResultsPanel::class, ['seedingCampaign' => $this->run])
            ->assertSee('2.00')
            ->assertSee('200.00');
    }

    public function test_cpe_and_cpm_are_unavailable_without_spend(): void
    {
        $this->actingAsCrmStaff();
        $this->seedResults();

        Livewire::test(SeedingResultsPanel::class, ['seedingCampaign' => $this->run])
            ->assertSee('Requires agency-entered spend (AC-M3-015)');
    }

    public function test_cpe_is_unavailable_with_no_observed_engagement_never_zero_or_infinity(): void
    {
        $this->actingAsCrmStaff();
        $this->run->update(['spend' => new MetricValue(100.0, MetricTier::Confirmed, 'spend')]);
        // Views only: no engagement component was ever observed (DP-001).
        $this->seedResults(['views' => 500]);

        Livewire::test(SeedingResultsPanel::class, ['seedingCampaign' => $this->run])
            ->assertSee('No engagement observed yet')
            ->assertSee('200.00'); // CPM still computes from the observed views
    }

    public function test_reach_renders_unavailable_when_not_yet_computed(): void
    {
        $this->actingAsCrmStaff();
        $this->seedResults();

        // No active reach configuration/computed reach for this run: the
        // tile is honest about "not yet" (REQ-M1-006) — never DEF-003, and
        // the retired "True unique reach" placeholder tile is gone.
        Livewire::test(SeedingResultsPanel::class, ['seedingCampaign' => $this->run])
            ->assertSee('No estimated reach for this run yet')
            ->assertSee('REQ-M1-006')
            ->assertDontSee('DEF-003')
            ->assertDontSee('True unique reach');
    }

    public function test_emv_disclosure_cites_the_producing_model(): void
    {
        // Deep-review M4: producing models, not the merely-active config —
        // an active model with no computed results discloses nothing.
        $this->actingAsCrmStaff();

        EmvConfiguration::factory()->active()->create(['name' => 'Benchmark 2027']);

        Livewire::test(SeedingResultsPanel::class, ['seedingCampaign' => $this->run])
            ->assertSee('no EMV has been computed')
            ->assertDontSee('Benchmark 2027');

        $producing = EmvConfiguration::factory()->create(['name' => 'Benchmark 2026']);
        EmvResult::create([
            'content_item_id' => ContentItem::factory()->create()->id,
            'emv_configuration_id' => $producing->id,
            'formula_version' => $producing->formula_version,
            'rate_card_version' => $producing->rate_card_version,
            'currency' => $producing->currency,
            'value' => new MetricValue(42.5, MetricTier::Estimated, 'emv'),
            'inputs' => [],
            'calculated_at' => now(),
        ]);

        Livewire::test(SeedingResultsPanel::class, ['seedingCampaign' => $this->run])
            ->assertSee('Benchmark 2026');
    }

    public function test_an_empty_run_shows_real_zero_counts_and_unavailable_sums(): void
    {
        $this->actingAsCrmStaff();

        // Counts of zero ARE measurements (0 / 0 shipments); unmeasured
        // sums surface as "unavailable" — never a fabricated zero.
        Livewire::test(SeedingResultsPanel::class, ['seedingCampaign' => $this->run])
            ->assertSee('0 / 0')
            ->assertSee('No observed views for this seeding run')
            ->assertSee('No shipment results in the rollups yet');
    }
}
