<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Campaigns\CampaignsIndex;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CampaignFormSplitTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_create_saves_as_draft_and_ignores_tampered_status_and_spend(): void
    {
        $this->actingAsCrmStaff();
        $brand = Brand::factory()->create();

        Livewire::test(CampaignsIndex::class)
            ->call('create')
            ->set('campaign_name', 'Spring Push')
            ->set('campaign_brand_id', (string) $brand->id)
            ->set('campaign_status', CampaignStatus::Active->value) // tampering — must be ignored
            ->set('campaign_spend', '999')                          // tampering — must be ignored
            ->call('save')
            ->assertHasNoErrors();

        $campaign = Campaign::query()->where('name', 'Spring Push')->firstOrFail();
        $this->assertSame(CampaignStatus::Draft, $campaign->status);
        $this->assertNull($campaign->spend);
    }

    public function test_create_modal_shows_no_status_or_spend_fields(): void
    {
        $this->actingAsCrmStaff();
        Brand::factory()->create();

        Livewire::test(CampaignsIndex::class)
            ->call('create')
            ->assertDontSeeHtml('id="campaign_status"')
            ->assertDontSeeHtml('id="campaign_spend"');
    }

    public function test_edit_modal_still_edits_status_and_spend(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();

        Livewire::test(CampaignsIndex::class)
            ->call('edit', $campaign->id)
            ->assertSeeHtml('id="campaign_status"')
            ->assertSeeHtml('id="campaign_spend"')
            ->set('campaign_status', CampaignStatus::Paused->value)
            ->set('campaign_spend', '1500')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
        $this->assertSame(1500.0, $campaign->fresh()->spend->amount);
    }
}
