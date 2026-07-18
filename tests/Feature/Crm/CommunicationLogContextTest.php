<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Creators\CommunicationLogPanel;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\CommunicationLog;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Communication-log context (F18): the creator comms form gets optional
 * Campaign / Seeding-run anchors, alongside the required creator. Fixes the
 * previously dead `campaign_id` column and surfaces Task 1's
 * `seeding_campaign_id` + `seedingCampaign()`.
 */
class CommunicationLogContextTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_logging_an_entry_with_a_campaign_and_seeding_run_persists_both(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $campaign = Campaign::factory()->create();
        $run = SeedingCampaign::factory()->create();

        Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('log_channel', 'email')
            ->set('log_direction', 'outbound')
            ->set('log_summary', 'Sent the autumn seeding brief.')
            ->set('log_occurred_at', '2026-07-06T09:30')
            ->set('log_campaign_id', (string) $campaign->id)
            ->set('log_seeding_campaign_id', (string) $run->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('communication_logs', [
            'creator_id' => $creator->id,
            'campaign_id' => $campaign->id,
            'seeding_campaign_id' => $run->id,
        ]);
    }

    public function test_campaign_and_seeding_run_stay_optional(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();

        Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('log_channel', 'call')
            ->set('log_direction', 'inbound')
            ->set('log_summary', 'Quick check-in, no campaign attached.')
            ->set('log_occurred_at', '2026-07-06T09:30')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('communication_logs', [
            'creator_id' => $creator->id,
            'campaign_id' => null,
            'seeding_campaign_id' => null,
            'summary' => 'Quick check-in, no campaign attached.',
        ]);
    }

    public function test_a_foreign_tenant_campaign_id_is_rejected(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $tenantB = $this->makeTenant('Tenant B');
        $foreignCampaign = $this->withTenant($tenantB, fn () => Campaign::factory()->create());

        Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('log_channel', 'email')
            ->set('log_direction', 'outbound')
            ->set('log_summary', 'Malicious cross-tenant link.')
            ->set('log_occurred_at', '2026-07-06T09:30')
            ->set('log_campaign_id', (string) $foreignCampaign->id)
            ->call('save')
            ->assertHasErrors(['log_campaign_id']);

        $this->assertDatabaseMissing('communication_logs', ['summary' => 'Malicious cross-tenant link.']);
    }

    public function test_a_foreign_tenant_seeding_run_id_is_rejected(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $tenantB = $this->makeTenant('Tenant B');
        $foreignRun = $this->withTenant($tenantB, fn () => SeedingCampaign::factory()->create());

        Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('log_channel', 'email')
            ->set('log_direction', 'outbound')
            ->set('log_summary', 'Malicious cross-tenant link.')
            ->set('log_occurred_at', '2026-07-06T09:30')
            ->set('log_seeding_campaign_id', (string) $foreignRun->id)
            ->call('save')
            ->assertHasErrors(['log_seeding_campaign_id']);

        $this->assertDatabaseMissing('communication_logs', ['summary' => 'Malicious cross-tenant link.']);
    }

    public function test_editing_a_linked_entry_hydrates_and_persists_the_selects(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $campaign = Campaign::factory()->create();
        $run = SeedingCampaign::factory()->create();
        $log = CommunicationLog::factory()->create([
            'creator_id' => $creator->id,
            'campaign_id' => $campaign->id,
            'seeding_campaign_id' => $run->id,
        ]);

        Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])
            ->call('edit', $log->id)
            ->assertSet('log_campaign_id', (string) $campaign->id)
            ->assertSet('log_seeding_campaign_id', (string) $run->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($campaign->id, $log->refresh()->campaign_id);
        $this->assertSame($run->id, $log->refresh()->seeding_campaign_id);
    }

    public function test_a_view_only_users_save_with_context_fields_is_forbidden(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $creator = Creator::factory()->create();
        $campaign = Campaign::factory()->create();

        Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])
            ->set('log_channel', 'email')
            ->set('log_direction', 'outbound')
            ->set('log_summary', 'smuggled entry')
            ->set('log_occurred_at', '2026-07-06T09:30')
            ->set('log_campaign_id', (string) $campaign->id)
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseMissing('communication_logs', ['summary' => 'smuggled entry']);
    }
}
