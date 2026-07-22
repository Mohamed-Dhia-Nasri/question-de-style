<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Campaigns\CampaignStatusActions;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Item 6b (Stage D task 10): gentle one-click next-step prompts on the
 * campaign Overview tab. At most one suggestion shows at a time; the button
 * re-authorizes crm.manage, re-checks the trigger still holds (stale guard),
 * sets the status, and records the transition. There is no scheduler and no
 * auto-transition — a person always clicks. (The seeding-run completion nudge
 * was removed at the operator's request; run status is set on the Seeding runs
 * list.)
 */
class StatusSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_a_draft_campaign_with_a_roster_suggests_planned_and_apply_moves_it(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Draft]);
        $creator = Creator::factory()->create();
        $campaign->creators()->syncWithoutDetaching([$creator->id]);

        Livewire::test(CampaignStatusActions::class, ['campaign' => $campaign])
            ->assertOk()
            ->assertSee('mark this campaign as Planned')
            ->call('applyStatus')
            ->assertOk();

        $this->assertSame(CampaignStatus::Planned, $campaign->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'campaign.status_changed',
            'subject_id' => $campaign->id,
        ]);
    }

    public function test_a_planned_campaign_past_start_suggests_starting_it(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create([
            'status' => CampaignStatus::Planned,
            'start_at' => now()->subDay(),
        ]);

        Livewire::test(CampaignStatusActions::class, ['campaign' => $campaign])
            ->assertOk()
            ->assertSee('start the campaign');
    }

    public function test_a_campaign_with_no_trigger_shows_no_banner(): void
    {
        $this->actingAsCrmStaff();

        // Draft with an empty roster — none of the three triggers fire.
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Draft]);

        Livewire::test(CampaignStatusActions::class, ['campaign' => $campaign])
            ->assertOk()
            ->assertDontSee('mark this campaign as Planned')
            ->assertDontSee('start the campaign')
            ->assertDontSee('mark the campaign as Completed');
    }

    public function test_apply_status_requires_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        // A user holding only crm.view can mount the banner (a read), but the
        // one-click action re-authorizes server-side against crm.manage.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Draft]);
        $creator = Creator::factory()->create();
        $campaign->creators()->syncWithoutDetaching([$creator->id]);

        Livewire::test(CampaignStatusActions::class, ['campaign' => $campaign])
            ->assertOk()
            ->call('applyStatus')
            ->assertForbidden();

        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'campaign.status_changed',
            'subject_id' => $campaign->id,
        ]);
    }

    public function test_a_stale_suggestion_is_a_no_op(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Draft]);
        $creator = Creator::factory()->create();
        $campaign->creators()->syncWithoutDetaching([$creator->id]);

        $component = Livewire::test(CampaignStatusActions::class, ['campaign' => $campaign])
            ->assertSee('mark this campaign as Planned');

        // The trigger stops holding underneath the open banner (the roster is
        // emptied by someone else). Applying it must be a silent no-op.
        $campaign->creators()->detach();

        $component->call('applyStatus')->assertOk();

        $this->assertSame(CampaignStatus::Draft, $campaign->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'campaign.status_changed',
            'subject_id' => $campaign->id,
        ]);
    }
}
