<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Campaigns\CampaignCreatorsPanel;
use App\Modules\CRM\Livewire\Results\CampaignResultsPanel;
use App\Modules\CRM\Models\Campaign;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_detail_page_has_tabs_and_still_mounts_all_panels(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();

        $this->get('/crm/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertSee('Overview')
            ->assertSeeLivewire(CampaignCreatorsPanel::class)
            ->assertSeeLivewire(CampaignResultsPanel::class);
    }

    public function test_draft_campaign_shows_the_setup_guide(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Draft, 'start_at' => null, 'end_at' => null]);

        $this->get('/crm/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertSee('Finish setting up')
            ->assertSee('Set the campaign dates');
    }

    public function test_active_campaign_does_not_show_the_setup_guide(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Active]);

        $this->get('/crm/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertDontSee('Finish setting up');
    }
}
