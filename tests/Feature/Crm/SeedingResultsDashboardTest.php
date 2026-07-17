<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Results\SeedingResultsDashboard;
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
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Platform\Analytics\Contracts\AnalyticsService;
use App\Platform\Export\Models\ExportJob;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Product aggregation dashboard at /crm/results (REQ-M3-013, AC-M3-019):
 * one cross-influencer total per product from ROLLUP-SeedingByProduct,
 * platform/content-type/country slices from the additive slice view (D5),
 * server-side validated filters, and no estimate presented as fact.
 */
class SeedingResultsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private Product $product;

    private SeedingCampaign $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        $client = Client::factory()->create();
        $this->brand = Brand::factory()->create(['client_id' => $client->id]);
        $this->product = Product::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Silk Serum']);
        $this->run = SeedingCampaign::factory()->create([
            'brand_id' => $this->brand->id,
            'product_id' => $this->product->id,
            'campaign_id' => Campaign::factory()->create(['brand_id' => $this->brand->id])->id,
        ]);
    }

    private function actingAsCrmStaff(): User
    {
        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    /** Ship → post → measure on Instagram, then refresh (views 500). */
    private function seedResults(): void
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
            'metrics' => [
                new MetricValue(500, MetricTier::Public, 'views'),
                new MetricValue(40, MetricTier::Public, 'likes'),
                new MetricValue(10, MetricTier::Public, 'comments'),
            ],
            'provenance' => new Provenance('SRC-apify-instagram-scraper', now()->toImmutable(), 'v1'),
        ]);

        app(AnalyticsService::class)->refreshRollups();
    }

    public function test_the_results_page_renders_for_staff(): void
    {
        $this->actingAsCrmStaff();

        $this->get('/crm/results')
            ->assertOk()
            ->assertSeeLivewire(SeedingResultsDashboard::class);
    }

    public function test_client_viewers_cannot_reach_the_page_or_mount_the_dashboard(): void
    {
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $this->get('/crm/results')->assertForbidden();
        Livewire::test(SeedingResultsDashboard::class)->assertForbidden();
    }

    public function test_product_totals_render_with_tier_badges_and_derived_post_rate(): void
    {
        $this->actingAsCrmStaff();
        $this->seedResults();

        Livewire::test(SeedingResultsDashboard::class)
            ->assertSee('Silk Serum')
            ->assertSee('100.0%')  // post_rate 1/1, DERIVED at the rollup grain
            ->assertSee('500')     // views [PUBLIC]
            ->assertSee('From platform')
            ->assertSee('Calculated')
            // reach honesty — no estimated reach computed for this slice
            // yet, never a fabricated number, and never DEF-003 (that's
            // CONFIRMED-reach only, retired from this ESTIMATED surface).
            ->assertSee('No estimated reach yet')
            ->assertSee('Settings → Reach')
            ->assertDontSee('DEF-003')
            // Product name is a link back to its record (Stage B Task 8).
            ->assertSee(route('crm.products.index', ['q' => 'Silk Serum']), false);
    }

    public function test_an_unknown_grain_falls_back_to_month(): void
    {
        $this->actingAsCrmStaff();
        $this->seedResults();

        Livewire::test(SeedingResultsDashboard::class)
            ->set('grain', 'bogus')
            ->assertSee('Silk Serum');
    }

    public function test_brand_and_product_filters_scope_the_rows(): void
    {
        $this->actingAsCrmStaff();
        $this->seedResults();

        Livewire::test(SeedingResultsDashboard::class)
            ->set('brandId', $this->brand->id)
            ->assertSee('Silk Serum')
            ->set('brandId', Brand::factory()->create()->id)
            ->assertSee('No seeding results yet');

        Livewire::test(SeedingResultsDashboard::class)
            ->set('productId', Product::factory()->create()->id)
            ->assertSee('No seeding results yet');
    }

    public function test_a_platform_slice_switches_to_content_side_measures_with_a_caption(): void
    {
        $this->actingAsCrmStaff();
        $this->seedResults();

        // Matching slice: content measures render, shipment columns are
        // explained away (shipments carry no platform dimension).
        Livewire::test(SeedingResultsDashboard::class)
            ->set('platform', 'INSTAGRAM')
            ->assertSee('Filtered view')
            ->assertSee('Silk Serum')
            ->assertSee('500')
            ->assertDontSee('Post rate');

        // Non-matching slice: honest empty state, never zeros.
        Livewire::test(SeedingResultsDashboard::class)
            ->set('platform', 'TIKTOK')
            ->assertSee('No seeding results yet');
    }

    public function test_a_content_type_slice_filters_rows(): void
    {
        $this->actingAsCrmStaff();
        $this->seedResults();

        Livewire::test(SeedingResultsDashboard::class)
            ->set('contentType', 'REEL')
            ->assertSee('Filtered view')
            ->assertSee('Silk Serum');

        Livewire::test(SeedingResultsDashboard::class)
            ->set('contentType', 'VIDEO')
            ->assertSee('No seeding results yet');
    }

    public function test_country_slices_are_unavailable_until_module_2(): void
    {
        $this->actingAsCrmStaff();
        $this->seedResults();

        // DIM-Geo is empty until Module 2 — a country slice matches nothing.
        Livewire::test(SeedingResultsDashboard::class)
            ->set('country', 'FR')
            ->assertSee('Filtered view')
            ->assertSee('No seeding results yet');

        // Malformed country input is ignored server-side (no slice), never
        // passed through to SQL.
        Livewire::test(SeedingResultsDashboard::class)
            ->set('country', 'fr!')
            ->assertSee('Post rate')
            ->assertSee('Silk Serum');
    }

    public function test_emv_disclosure_cites_the_producing_model(): void
    {
        // Deep-review M4: producing models, not the merely-active config.
        $this->actingAsCrmStaff();
        $this->seedResults();

        Livewire::test(SeedingResultsDashboard::class)
            ->assertSee('No EMV yet');

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

        Livewire::test(SeedingResultsDashboard::class)
            ->assertSee('Benchmark 2026');
    }

    public function test_export_queues_a_job_carrying_the_current_view_filters(): void
    {
        Queue::fake();
        $this->actingAsCrmStaff();
        $this->seedResults();
        app(AnalyticsService::class)->refreshRollups();

        Livewire::test(SeedingResultsDashboard::class)
            ->set('grain', 'year')
            ->set('brandId', $this->brand->id)
            ->set('productId', $this->product->id)
            ->set('platform', 'INSTAGRAM')
            ->set('exportFormat', 'EXCEL')
            ->call('export');

        $job = ExportJob::query()->sole();
        $this->assertSame('seeding-results', $job->report);
        $this->assertSame('EXCEL', $job->format->value);
        $this->assertSame('year', $job->filters['grain']);
        $this->assertSame($this->brand->id, $job->filters['brand_id']);
        $this->assertSame($this->product->id, $job->filters['product_id']);
        $this->assertSame('INSTAGRAM', $job->filters['platform']);
    }

    public function test_export_requires_the_exports_permission_not_just_crm_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        // Direct-mutator bypass included: state set, then the action called.
        Livewire::test(SeedingResultsDashboard::class)
            ->set('exportFormat', 'CSV')
            ->call('export')
            ->assertForbidden();

        $this->assertDatabaseCount('export_jobs', 0);
    }

    public function test_a_city_slice_renders_without_error_once_geography_is_assigned(): void
    {
        // Regression (review B1): the slice view lacked a city column while
        // the dashboard filtered on it — the first real city selection
        // crashed. City slices must both filter and render.
        $this->actingAsCrmStaff();
        $this->seedResults();

        $creator = Creator::query()->firstOrFail();
        app(CreatorGeography::class)->assign($creator, 'DE', null, 'Munich');
        app(AnalyticsService::class)->refreshRollups();

        Livewire::test(SeedingResultsDashboard::class)
            ->set('city', 'Munich')
            ->assertSee($this->product->name)
            ->assertSee('500');

        // A REAL city with no seeded content filters to the honest empty
        // state; an arbitrary unknown city is IGNORED by design (it never
        // reaches SQL), leaving the unsliced view.
        $bystander = Creator::factory()->create();
        app(CreatorGeography::class)->assign($bystander, 'DE', null, 'Berlin');
        app(AnalyticsService::class)->refreshRollups();

        Livewire::test(SeedingResultsDashboard::class)
            ->set('city', 'Berlin')
            ->assertSee('No seeding results');
    }

    public function test_the_empty_dashboard_shows_the_honest_empty_state(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(SeedingResultsDashboard::class)
            ->assertSee('No seeding results yet')
            ->assertSee('Data refreshed');
    }
}
