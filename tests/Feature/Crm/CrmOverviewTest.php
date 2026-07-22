<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Overview\CrmOverview;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\CRM\Models\Task;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\ShipmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CRM Overview (F02, Stage C Task 11) — the `/crm` hub becomes an
 * operational home: setup checklist, needs-attention queue, active work,
 * quick actions. Covers the empty-tenant checklist, its collapse into a
 * success pill once every step is done, the three needs-attention counts,
 * the crm.view/crm.manage split, and the quick-action ?create=1 auto-open
 * on the four index components.
 */
class CrmOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();

        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_empty_tenant_sees_the_full_checklist_with_first_step_open(): void
    {
        $this->actingAsCrmStaff();

        $this->get('/crm')->assertOk()->assertSeeLivewire(CrmOverview::class)
            ->assertSee('Get set up')->assertSee('Create your first client');
    }

    public function test_checklist_disappears_and_headline_numbers_show_once_everything_exists(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create(); // → client+brand via nested factories
        Creator::factory()->create();
        SeedingCampaign::factory()->forCampaign($campaign)->create(['brand_id' => $campaign->brand_id]);

        Livewire::test(CrmOverview::class)
            // The setup card is gone once every step exists.
            ->assertDontSee('Get set up')
            ->assertDontSee('Create your first client')
            // The clickable headline strip replaces it.
            ->assertSee('Clients')
            ->assertSee('Creators')
            ->assertSee('Active campaigns')
            ->assertSee('Active runs');
    }

    public function test_headline_numbers_and_active_work_with_run_progress_render(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create(['name' => 'Spring Launch', 'status' => CampaignStatus::Active]);
        $run = SeedingCampaign::factory()->forCampaign($campaign)->create([
            'brand_id' => $campaign->brand_id,
            'name' => 'Nike Seeding',
            'status' => SeedingCampaignStatus::Shipping,
        ]);
        $creator = Creator::factory()->create();
        $run->creators()->syncWithoutDetaching([$creator->id]);
        Shipment::factory()->create([
            'seeding_campaign_id' => $run->id, 'creator_id' => $creator->id,
            'status' => ShipmentStatus::Delivered, 'shipped_at' => now()->subDays(3), 'delivered_at' => now()->subDay(),
        ]);

        Livewire::test(CrmOverview::class)
            ->assertViewHas('kpis', fn (array $kpis): bool => collect($kpis)->firstWhere('label', 'Active campaigns')['value'] === 1
                && collect($kpis)->firstWhere('label', 'Active runs')['value'] === 1)
            ->assertSee('Spring Launch')   // active campaign row
            ->assertSee('Nike Seeding')    // active seeding run row
            ->assertSee('1/1 delivered');  // run progress read
    }

    public function test_needs_attention_names_the_specific_overdue_tasks_empty_runs_and_stale_shipments(): void
    {
        $this->actingAsCrmStaff();

        Task::factory()->create(['title' => 'Chase Nova about the brief', 'due_at' => now()->subDay()]);
        SeedingCampaign::factory()->create(['name' => 'Lonely Run']); // Draft, no creators

        $creator = Creator::factory()->create(['display_name' => 'Nova Lang']);
        $run2 = SeedingCampaign::factory()->create(['name' => 'Nike Seeding']);
        $run2->creators()->syncWithoutDetaching([$creator->id]);
        Shipment::factory()->create([
            'seeding_campaign_id' => $run2->id, 'creator_id' => $creator->id,
            'status' => ShipmentStatus::Shipped, 'shipped_at' => now()->subDays(10), 'delivered_at' => null,
        ]);

        Livewire::test(CrmOverview::class)
            // Each alert now names WHICH record and deep-links to it.
            ->assertSee('Chase Nova about the brief')                                   // the overdue task
            ->assertSee('Lonely Run')                                                  // the creator-less run
            ->assertSee('Nova Lang · Nike Seeding')                                     // the stale parcel
            ->assertSee(route('crm.seeding.show', $run2).'#shipments', false);         // links to that run's shipments tab
    }

    public function test_client_viewer_gets_403(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $this->get('/crm')->assertForbidden();
    }

    public function test_a_view_only_user_on_an_empty_tenant_sees_the_campaign_step_as_plain_text_not_a_link(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        // A view-only user cannot create a campaign (CampaignPolicy::create
        // requires crm.manage), so the checklist row must not link to the
        // wizard — the wizard's mount() would 403 it. The label still shows.
        $this->get('/crm')->assertOk()
            ->assertSee('Create your first campaign')
            ->assertDontSee(route('crm.campaigns.create'));
    }

    public function test_crm_staff_sees_the_campaign_step_as_a_link_to_the_wizard(): void
    {
        $this->actingAsCrmStaff();

        $this->get('/crm')->assertOk()
            ->assertSee(route('crm.campaigns.create'));
    }

    public function test_quick_action_query_param_opens_the_create_modal(): void
    {
        $this->actingAsCrmStaff();

        $this->get('/crm/clients?create=1')->assertOk()->assertSee('New client');

        // A crm.view-only user still gets a working page, just no modal.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $this->get('/crm/clients?create=1')->assertOk();
    }
}
