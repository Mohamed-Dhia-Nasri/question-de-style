<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Campaigns\CampaignCreatorsPanel;
use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Campaign participation (campaign_creator pivot) with the AC-M3-007 hard
 * filter: a creator whose ENT-BrandPreference restriction list names the
 * campaign's brand is BLOCKED before commit — never silently attached.
 */
class CampaignCreatorsPanelTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_the_campaign_detail_page_renders_the_panel(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create();

        $this->get('/crm/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertSeeLivewire(CampaignCreatorsPanel::class);
    }

    public function test_client_viewers_cannot_reach_the_page_or_mount_the_panel(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $campaign = Campaign::factory()->create();

        $this->get('/crm/campaigns/'.$campaign->id)->assertForbidden();
        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])->assertForbidden();
    }

    public function test_attaching_a_creator_persists_the_pivot_with_an_audit_event(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create();
        $creator = Creator::factory()->create();

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->set('attach_creator_id', (string) $creator->id)
            ->call('attach')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('campaign_creator', [
            'campaign_id' => $campaign->id,
            'creator_id' => $creator->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campaign_creator.attached', 'subject_id' => $campaign->id]);
    }

    public function test_a_restricted_creator_is_blocked_before_commit(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create();
        $creator = Creator::factory()->create();
        BrandPreference::factory()->create([
            'creator_id' => $creator->id,
            // Case-insensitive name match (spec D4).
            'restricted_brands' => [mb_strtoupper($campaign->brand->name)],
        ]);

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->set('attach_creator_id', (string) $creator->id)
            ->call('attach')
            ->assertHasErrors(['attach_creator_id']);

        // AC-M3-007: blocked BEFORE commit — no pivot row exists.
        $this->assertDatabaseMissing('campaign_creator', [
            'campaign_id' => $campaign->id,
            'creator_id' => $creator->id,
        ]);
    }

    public function test_attaching_twice_is_idempotent(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create();
        $creator = Creator::factory()->create();
        $campaign->creators()->attach($creator->id);

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->set('attach_creator_id', (string) $creator->id)
            ->call('attach')
            ->assertHasNoErrors();

        $this->assertSame(1, $campaign->creators()->count());
    }

    public function test_detaching_removes_the_pivot_with_an_audit_event(): void
    {
        $this->actingAsCrmStaff();

        $campaign = Campaign::factory()->create();
        $creator = Creator::factory()->create();
        $campaign->creators()->attach($creator->id);

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('confirmDetach', $creator->id)
            ->call('detach');

        $this->assertDatabaseMissing('campaign_creator', [
            'campaign_id' => $campaign->id,
            'creator_id' => $creator->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campaign_creator.detached', 'subject_id' => $campaign->id]);
    }

    public function test_mutating_actions_require_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $campaign = Campaign::factory()->create();
        $creator = Creator::factory()->create();
        $campaign->creators()->attach($creator->id);

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])->assertOk()
            ->set('attach_creator_id', (string) Creator::factory()->create()->id)
            ->call('attach')->assertForbidden();
        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->set('confirmingDetachId', $creator->id)
            ->call('detach')->assertForbidden();

        $this->assertSame(1, $campaign->creators()->count());
    }
}
