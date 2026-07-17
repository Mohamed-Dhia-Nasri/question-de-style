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
use App\Shared\Enums\RoleName;
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

    public function test_checklist_collapses_to_a_pill_when_everything_exists(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create(); // → client+brand via nested factories
        Creator::factory()->create();
        SeedingCampaign::factory()->forCampaign($campaign)->create(['brand_id' => $campaign->brand_id]);

        Livewire::test(CrmOverview::class)
            ->assertSee('You’re all set up')
            ->assertDontSee('Create your first client');
    }

    public function test_needs_attention_counts_overdue_tasks_empty_runs_and_stale_shipments(): void
    {
        $this->actingAsCrmStaff();

        Task::factory()->create(['due_at' => now()->subDay()]); // overdue (status Open default)
        SeedingCampaign::factory()->create(); // Draft, no creators
        $creator = Creator::factory()->create();
        $run2 = SeedingCampaign::factory()->create();
        $run2->creators()->syncWithoutDetaching([$creator->id]);
        Shipment::factory()->create([
            'seeding_campaign_id' => $run2->id, 'creator_id' => $creator->id,
            'status' => ShipmentStatus::Shipped, 'shipped_at' => now()->subDays(10), 'delivered_at' => null,
        ]);

        Livewire::test(CrmOverview::class)
            ->assertSee('1 task is overdue')
            ->assertSee('1 seeding run has no creators yet')
            ->assertSee('on the road for more than a week');
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
