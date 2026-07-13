<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Creators\CommunicationLogPanel;
use App\Modules\CRM\Models\CommunicationLog;
use App\Modules\CRM\Models\Creator;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Communication log panel (REQ-M3-004, AC-M3-008): append/list of
 * channel/direction/summary/occurredAt entries. Append-mostly — entries are
 * editable (operator-authored, not an immutable AI-correction trail) but
 * there is no delete action.
 */
class CommunicationLogPanelTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_client_viewers_cannot_mount_the_panel(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        Livewire::test(CommunicationLogPanel::class, ['creator' => Creator::factory()->create()])
            ->assertForbidden();
    }

    public function test_an_operator_logs_an_interaction(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();

        Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('log_channel', 'email')
            ->set('log_direction', 'outbound')
            ->set('log_summary', 'Sent the autumn seeding brief.')
            ->set('log_occurred_at', '2026-07-06T09:30')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('communication_logs', [
            'creator_id' => $creator->id,
            'channel' => 'email',
            'direction' => 'outbound',
            'summary' => 'Sent the autumn seeding brief.',
            // The operator-supplied occurredAt is persisted — not now().
            'occurred_at' => '2026-07-06 09:30:00',
        ]);
    }

    public function test_entries_are_validated_server_side(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();

        Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('log_channel', '')
            ->set('log_direction', 'sideways')
            ->set('log_summary', '')
            ->set('log_occurred_at', 'not-a-date')
            ->call('save')
            ->assertHasErrors([
                'log_channel' => 'required',
                'log_direction' => 'in',
                'log_summary' => 'required',
                'log_occurred_at' => 'date',
            ]);
    }

    public function test_entries_are_listed_newest_first(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        CommunicationLog::factory()->create([
            'creator_id' => $creator->id,
            'summary' => 'older entry',
            'occurred_at' => now()->subDays(3),
        ]);
        CommunicationLog::factory()->create([
            'creator_id' => $creator->id,
            'summary' => 'newer entry',
            'occurred_at' => now()->subDay(),
        ]);

        Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])
            ->assertSeeInOrder(['newer entry', 'older entry']);
    }

    public function test_an_operator_can_correct_an_entry_but_there_is_no_delete_action(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $log = CommunicationLog::factory()->create(['creator_id' => $creator->id, 'summary' => 'typo entry']);

        $component = Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])
            ->call('edit', $log->id)
            ->assertSet('log_summary', 'typo entry')
            ->set('log_summary', 'corrected entry')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('communication_logs', ['id' => $log->id, 'summary' => 'corrected entry']);

        // Append-mostly: no delete method exists on the panel.
        $this->assertFalse(method_exists($component->instance(), 'delete'));
    }

    public function test_mutating_actions_require_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        // A user holding ONLY crm.view can mount the panel, but every
        // mutating action re-authorizes server-side against crm.manage.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $creator = Creator::factory()->create();
        $log = CommunicationLog::factory()->create(['creator_id' => $creator->id]);

        Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])->assertOk()
            ->call('add')->assertForbidden();
        Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])
            ->call('edit', $log->id)->assertForbidden();

        // The persisting mutator re-authorizes itself even when the gated
        // form-open actions are bypassed via direct state writes.
        Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])
            ->set('log_channel', 'email')
            ->set('log_direction', 'outbound')
            ->set('log_summary', 'smuggled entry')
            ->set('log_occurred_at', '2026-07-06T09:30')
            ->call('save')->assertForbidden();

        $this->assertDatabaseMissing('communication_logs', ['summary' => 'smuggled entry']);
    }
}
