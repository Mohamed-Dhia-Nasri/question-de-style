<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Campaigns\CampaignsIndex;
use App\Modules\CRM\Models\Campaign;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Campaign brief (objective + markets) — edit-only, mirroring the Stage C
 * spend/status split: the create modal never asks for a brief, and markets
 * is a jsonb list stored one-per-line in the textarea.
 */
class CampaignBriefTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_editing_sets_objective_and_markets(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create(['objective' => null, 'markets' => null]);

        Livewire::test(CampaignsIndex::class)
            ->call('edit', $campaign->id)
            ->set('campaign_objective', 'Drive awareness ahead of the launch.')
            ->set('campaign_markets', "Germany\nAustria")
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $campaign->fresh();
        $this->assertSame('Drive awareness ahead of the launch.', $fresh->objective);
        $this->assertSame(['Germany', 'Austria'], $fresh->markets);
    }

    public function test_markets_textarea_drops_blank_lines(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();

        Livewire::test(CampaignsIndex::class)
            ->call('edit', $campaign->id)
            ->set('campaign_markets', "Germany\n\n  \nAustria\n")
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['Germany', 'Austria'], $campaign->fresh()->markets);
    }

    public function test_blank_objective_and_markets_clear_the_stored_brief(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create([
            'objective' => 'Old objective',
            'markets' => ['France'],
        ]);

        Livewire::test(CampaignsIndex::class)
            ->call('edit', $campaign->id)
            ->set('campaign_objective', '')
            ->set('campaign_markets', '')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $campaign->fresh();
        $this->assertNull($fresh->objective);
        $this->assertNull($fresh->markets);
    }

    public function test_edit_modal_hydrates_the_existing_brief(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create([
            'objective' => 'Grow trial signups.',
            'markets' => ['Germany', 'Austria'],
        ]);

        Livewire::test(CampaignsIndex::class)
            ->call('edit', $campaign->id)
            ->assertSet('campaign_objective', 'Grow trial signups.')
            ->assertSet('campaign_markets', "Germany\nAustria");
    }

    public function test_create_modal_shows_no_brief_fields(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(CampaignsIndex::class)
            ->call('create')
            ->assertDontSeeHtml('id="campaign_objective"')
            ->assertDontSeeHtml('id="campaign_markets"');
    }

    public function test_edit_modal_shows_brief_fields(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();

        Livewire::test(CampaignsIndex::class)
            ->call('edit', $campaign->id)
            ->assertSeeHtml('id="campaign_objective"')
            ->assertSeeHtml('id="campaign_markets"');
    }

    public function test_detail_page_shows_the_brief_when_set(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create([
            'objective' => 'Drive Awareness ahead of the launch.',
            'markets' => ['Germany', 'Austria'],
        ]);

        $this->get('/crm/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertSee('Brief')
            ->assertSee('Drive Awareness ahead of the launch.')
            ->assertSee('Germany')
            ->assertSee('Austria');
    }

    public function test_detail_page_omits_the_brief_card_when_empty(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create(['objective' => null, 'markets' => null]);

        $this->get('/crm/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertDontSee('Brief');
    }
}
