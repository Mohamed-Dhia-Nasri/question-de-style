<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Campaigns\CampaignCreatorsPanel;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Campaign participation (campaign_creator pivot). Adding creators is the
 * searchable multi-select picker (see CampaignRosterPickerTest); this file
 * covers the panel shell: page render, view authorization, and the detach
 * flow (unguarded by design — the asymmetry with seeding is intentional).
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

    public function test_detach_requires_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $campaign = Campaign::factory()->create();
        $creator = Creator::factory()->create();
        $campaign->creators()->attach($creator->id);

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])->assertOk()
            ->call('confirmDetach', $creator->id)->assertForbidden();
        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->set('confirmingDetachId', $creator->id)
            ->call('detach')->assertForbidden();

        $this->assertSame(1, $campaign->creators()->count());
    }
}
