<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Creators\CommunicationLogPanel;
use App\Modules\CRM\Models\Creator;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Task 11 (§2.5, item 6d): after logging an OUTBOUND message for a creator
 * who has no relationship yet (None / Prospect / null), the panel offers a
 * SOFT one-tap "Mark as Contacted?" nudge. It never changes the stage on its
 * own — the operator taps markContacted (which authorizes + audits) or
 * dismisses it.
 */
class RelationshipAutoSuggestTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    private function logOutbound(Creator $creator): Testable
    {
        return Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('log_channel', 'email')
            ->set('log_direction', 'outbound')
            ->set('log_summary', 'Sent the intro note.')
            ->set('log_occurred_at', '2026-07-06T09:30')
            ->call('save');
    }

    public function test_an_outbound_log_for_a_prospect_creator_suggests_contacted(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create(['relationship_status' => RelationshipStatus::Prospect]);

        $this->logOutbound($creator)
            ->assertHasNoErrors()
            ->assertSet('suggestContacted', true)
            ->assertSee('as Contacted?')
            ->assertSee($creator->display_name);
    }

    public function test_an_outbound_log_for_a_creator_with_no_stage_suggests_contacted(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create(['relationship_status' => null]);

        $this->logOutbound($creator)
            ->assertSet('suggestContacted', true);
    }

    public function test_marking_contacted_updates_the_stage_and_audits(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create(['relationship_status' => RelationshipStatus::Prospect]);

        $this->logOutbound($creator)
            ->assertSet('suggestContacted', true)
            ->call('markContacted')
            ->assertHasNoErrors()
            ->assertSet('suggestContacted', false);

        $this->assertSame(RelationshipStatus::Contacted, $creator->fresh()->relationship_status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'creator.relationship_changed',
            'subject_id' => $creator->id,
        ]);
    }

    public function test_an_outbound_log_for_an_active_creator_does_not_suggest(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create(['relationship_status' => RelationshipStatus::Active]);

        $this->logOutbound($creator)
            ->assertSet('suggestContacted', false);
    }

    public function test_an_inbound_log_does_not_suggest(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create(['relationship_status' => RelationshipStatus::Prospect]);

        Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('log_channel', 'email')
            ->set('log_direction', 'inbound')
            ->set('log_summary', 'They replied first.')
            ->set('log_occurred_at', '2026-07-06T09:30')
            ->call('save')
            ->assertSet('suggestContacted', false);
    }

    public function test_marking_contacted_requires_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $creator = Creator::factory()->create(['relationship_status' => RelationshipStatus::Prospect]);

        Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])->assertOk()
            ->set('suggestContacted', true)
            ->call('markContacted')->assertForbidden();

        $this->assertSame(RelationshipStatus::Prospect, $creator->fresh()->relationship_status);
    }

    public function test_dismissing_the_suggestion_clears_the_flag(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create(['relationship_status' => RelationshipStatus::Prospect]);

        Livewire::test(CommunicationLogPanel::class, ['creator' => $creator])
            ->set('suggestContacted', true)
            ->call('dismissContacted')
            ->assertSet('suggestContacted', false);
    }
}
