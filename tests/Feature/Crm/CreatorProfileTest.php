<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Creators\BrandPreferencesPanel;
use App\Modules\CRM\Livewire\Creators\CommunicationLogPanel;
use App\Modules\CRM\Livewire\Creators\ContactsPanel;
use App\Modules\CRM\Livewire\Creators\CreatorProfile;
use App\Modules\CRM\Livewire\Creators\PlatformAccountsPanel;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Ingestion\Jobs\RunCreatorCycleJob;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Creator profile page (spec §2.4): the identity card plus the four
 * relationship panels, all policy-gated. The editable relationship status
 * uses ENUM-RelationshipStatus (REQ-M3-004). There is no merge control
 * anywhere on the profile (ADR-0014).
 */
class CreatorProfileTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_the_profile_page_renders_the_identity_card_and_all_four_panels(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create(['display_name' => 'Profil Kreatorin']);

        $this->get('/crm/creators/'.$creator->id)
            ->assertOk()
            ->assertSee('Profil Kreatorin')
            ->assertSeeLivewire(CreatorProfile::class)
            ->assertSeeLivewire(PlatformAccountsPanel::class)
            ->assertSeeLivewire(ContactsPanel::class)
            ->assertSeeLivewire(BrandPreferencesPanel::class)
            ->assertSeeLivewire(CommunicationLogPanel::class);
    }

    public function test_a_missing_creator_yields_404(): void
    {
        $this->actingAsCrmStaff();

        $this->get('/crm/creators/999999')->assertNotFound();
    }

    public function test_client_viewers_cannot_reach_the_profile(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $creator = Creator::factory()->create();

        $this->get('/crm/creators/'.$creator->id)->assertForbidden();

        Livewire::test(CreatorProfile::class, ['creator' => $creator])->assertForbidden();
    }

    public function test_the_identity_form_updates_creator_fields_including_relationship_status(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create([
            'display_name' => 'Old Name',
            'relationship_status' => RelationshipStatus::Prospect,
        ]);

        Livewire::test(CreatorProfile::class, ['creator' => $creator])
            ->assertSet('display_name', 'Old Name')
            ->assertSet('relationship_status', RelationshipStatus::Prospect->value)
            ->set('display_name', 'New Name')
            ->set('relationship_status', RelationshipStatus::InConversation->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('creators', [
            'id' => $creator->id,
            'display_name' => 'New Name',
            'relationship_status' => 'IN_CONVERSATION',
        ]);
    }

    public function test_the_identity_form_validates_server_side(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();

        Livewire::test(CreatorProfile::class, ['creator' => $creator])
            ->set('display_name', '')
            ->set('relationship_status', 'SOULMATES')
            ->call('save')
            ->assertHasErrors([
                'display_name' => 'required',
                'relationship_status' => 'in',
            ]);
    }

    public function test_saving_the_identity_requires_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        // A user holding ONLY crm.view can mount the profile, but saving
        // re-authorizes server-side against crm.manage.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $creator = Creator::factory()->create();

        Livewire::test(CreatorProfile::class, ['creator' => $creator])->assertOk()
            ->set('display_name', 'Hijacked')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseMissing('creators', ['display_name' => 'Hijacked']);
    }

    // --- run monitoring now (on-demand single-creator cycle) ----------------

    public function test_run_monitoring_now_enrolls_the_creator_and_queues_an_on_demand_cycle(): void
    {
        config(['qds.ingestion.enabled' => true]);
        $this->actingAsCrmStaff();

        // A pre-enrollment creator (factory bypasses CreatorWriter): the
        // button must enroll it before polling, covering legacy rows.
        $creator = Creator::factory()->create();
        PlatformAccount::factory()->create(['creator_id' => $creator->id]);

        Queue::fake();

        Livewire::test(CreatorProfile::class, ['creator' => $creator])
            ->call('runMonitoringNow')
            ->assertOk()
            ->assertDispatched('notify', type: 'success');

        $this->assertDatabaseHas('monitored_subjects', [
            'creator_id' => $creator->id,
            'active' => true,
        ]);
        Queue::assertPushed(fn (RunCreatorCycleJob $job) => $job->creatorId === $creator->id);
    }

    public function test_run_monitoring_now_requires_the_roster_permission_not_just_crm_access(): void
    {
        config(['qds.ingestion.enabled' => true]);
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $creator = Creator::factory()->create();
        PlatformAccount::factory()->create(['creator_id' => $creator->id]);

        Queue::fake();

        Livewire::test(CreatorProfile::class, ['creator' => $creator])->assertOk()
            ->call('runMonitoringNow')
            ->assertForbidden();

        Queue::assertNotPushed(RunCreatorCycleJob::class);
    }

    public function test_run_monitoring_now_refuses_while_ingestion_is_disabled(): void
    {
        config(['qds.ingestion.enabled' => false]);
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        PlatformAccount::factory()->create(['creator_id' => $creator->id]);

        Queue::fake();

        Livewire::test(CreatorProfile::class, ['creator' => $creator])
            ->call('runMonitoringNow')
            ->assertDispatched('notify', type: 'error');

        Queue::assertNotPushed(RunCreatorCycleJob::class);
    }

    public function test_run_monitoring_now_refuses_a_creator_without_accounts(): void
    {
        config(['qds.ingestion.enabled' => true]);
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();

        Queue::fake();

        Livewire::test(CreatorProfile::class, ['creator' => $creator])
            ->call('runMonitoringNow')
            ->assertDispatched('notify', type: 'error');

        Queue::assertNotPushed(RunCreatorCycleJob::class);
    }
}
