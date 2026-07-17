<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Campaigns\CampaignsIndex;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Audit\AuditLog;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\RoleName;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Campaigns (REQ-M3-005, AC-M3-009): the status is always exactly one
 * ENUM-CampaignStatus value and every lifecycle transition is recorded
 * with from → to. UsersIndex-pattern CRUD coverage + view/manage split.
 */
class CampaignsCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_component_renders_and_client_viewers_are_refused(): void
    {
        $this->actingAsCrmStaff();
        $this->get('/crm/campaigns')->assertOk()->assertSeeLivewire(CampaignsIndex::class);

        $this->actingAs($this->makeUser(RoleName::ClientViewer));
        $this->get('/crm/campaigns')->assertForbidden();
        Livewire::test(CampaignsIndex::class)->assertForbidden();
    }

    public function test_search_and_status_filter_narrow_the_result(): void
    {
        $this->actingAsCrmStaff();

        Campaign::factory()->create(['name' => 'Frühjahr Launch', 'status' => CampaignStatus::Active]);
        Campaign::factory()->create(['name' => 'Winter Special', 'status' => CampaignStatus::Draft]);

        Livewire::test(CampaignsIndex::class)
            ->set('search', 'frühjahr')
            ->assertSee('Frühjahr Launch')
            ->assertDontSee('Winter Special')
            ->set('search', '')
            ->set('statusFilter', CampaignStatus::Draft->value)
            ->assertSee('Winter Special')
            ->assertDontSee('Frühjahr Launch');
    }

    public function test_create_validates_required_fields_and_date_order(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(CampaignsIndex::class)
            ->call('create')
            ->set('campaign_name', '')
            ->set('campaign_start_at', '2026-07-10T10:00')
            ->set('campaign_end_at', '2026-07-01T10:00')
            ->call('save')
            ->assertHasErrors([
                'campaign_name' => 'required',
                'campaign_brand_id' => 'required',
                'campaign_end_at' => 'after_or_equal',
            ]);
    }

    public function test_edit_validates_the_closed_status_set(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create();

        Livewire::test(CampaignsIndex::class)
            ->call('edit', $campaign->id)
            ->set('campaign_status', 'LAUNCHED')
            ->call('save')
            ->assertHasErrors(['campaign_status' => 'in']);
    }

    public function test_create_persists_as_a_draft_with_an_audit_event(): void
    {
        $this->actingAsCrmStaff();

        $brand = Brand::factory()->create();

        Livewire::test(CampaignsIndex::class)
            ->call('create')
            ->set('campaign_name', 'Sommer Kampagne')
            ->set('campaign_brand_id', (string) $brand->id)
            ->call('save')
            ->assertHasNoErrors();

        $campaign = Campaign::where('name', 'Sommer Kampagne')->firstOrFail();
        $this->assertSame(CampaignStatus::Draft, $campaign->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.created', 'subject_id' => $campaign->id]);
    }

    public function test_spend_is_stored_as_a_confirmed_metric_value(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create();

        Livewire::test(CampaignsIndex::class)
            ->call('edit', $campaign->id)
            ->set('campaign_spend', '1500.50')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $campaign->fresh();
        // AC-M3-015 input: agency-entered spend rides the envelope at tier CONFIRMED.
        $this->assertInstanceOf(MetricValue::class, $fresh->spend);
        $this->assertSame(1500.50, $fresh->spend->amount);
        $this->assertSame(MetricTier::Confirmed, $fresh->spend->tier);
        $this->assertSame('spend', $fresh->spend->metric);
    }

    public function test_blank_spend_clears_the_stored_value(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create([
            'spend' => new MetricValue(900.0, MetricTier::Confirmed, 'spend'),
        ]);

        Livewire::test(CampaignsIndex::class)
            ->call('edit', $campaign->id)
            ->set('campaign_spend', '')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $campaign->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->spend);
    }

    public function test_negative_spend_is_rejected(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create();

        Livewire::test(CampaignsIndex::class)
            ->call('edit', $campaign->id)
            ->set('campaign_spend', '-1')
            ->call('save')
            ->assertHasErrors(['campaign_spend' => 'min']);
    }

    public function test_status_transitions_are_recorded_with_from_and_to(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Draft]);

        Livewire::test(CampaignsIndex::class)
            ->call('edit', $campaign->id)
            ->set('campaign_status', CampaignStatus::Active->value)
            ->call('save')
            ->assertHasNoErrors();

        $log = AuditLog::query()
            ->where('action', 'campaign.status_changed')
            ->where('subject_id', $campaign->id)
            ->firstOrFail();

        $this->assertSame('DRAFT', $log->context['from']);
        $this->assertSame('ACTIVE', $log->context['to']);
    }

    public function test_editing_without_a_status_change_records_no_transition(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Active]);

        Livewire::test(CampaignsIndex::class)
            ->call('edit', $campaign->id)
            ->set('campaign_name', 'Renamed Campaign')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'campaign.status_changed',
            'subject_id' => $campaign->id,
        ]);
    }

    public function test_delete_is_refused_while_seeding_runs_reference_the_campaign(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create();
        SeedingCampaign::factory()->create(['campaign_id' => $campaign->id, 'brand_id' => $campaign->brand_id]);

        Livewire::test(CampaignsIndex::class)
            ->call('confirmDelete', $campaign->id)
            ->call('delete');

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id]);
    }

    public function test_mutating_actions_require_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $campaign = Campaign::factory()->create();

        Livewire::test(CampaignsIndex::class)->assertOk()
            ->call('create')->assertForbidden();
        Livewire::test(CampaignsIndex::class)
            ->set('campaign_name', 'Smuggled Campaign')
            ->call('save')->assertForbidden();
        Livewire::test(CampaignsIndex::class)
            ->set('confirmingDeleteId', $campaign->id)
            ->call('delete')->assertForbidden();

        $this->assertDatabaseMissing('campaigns', ['name' => 'Smuggled Campaign']);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id]);
    }
}
