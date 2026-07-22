<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Documents\DocumentsPanel;
use App\Modules\CRM\Livewire\Results\SeedingResultsPanel;
use App\Modules\CRM\Livewire\Seeding\SeedingCreatorsPanel;
use App\Modules\CRM\Livewire\Seeding\ShipmentsPanel;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\ShipmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedingDetailPageTest extends TestCase
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
        $seedingCampaign = SeedingCampaign::factory()->create();

        $this->get('/crm/seeding/'.$seedingCampaign->id)
            ->assertOk()
            ->assertSee('Overview')
            ->assertSeeLivewire(SeedingCreatorsPanel::class)
            ->assertSeeLivewire(ShipmentsPanel::class)
            ->assertSeeLivewire(SeedingResultsPanel::class)
            ->assertSeeLivewire(DocumentsPanel::class);
    }

    public function test_draft_run_shows_the_setup_guide(): void
    {
        $this->actingAsCrmStaff();
        $seedingCampaign = SeedingCampaign::factory()->create([
            'status' => SeedingCampaignStatus::Draft,
            'product_id' => null,
        ]);

        $this->get('/crm/seeding/'.$seedingCampaign->id)
            ->assertOk()
            ->assertSee('Finish setting up')
            ->assertSee('Choose a product');
    }

    public function test_active_run_does_not_show_the_setup_guide(): void
    {
        $this->actingAsCrmStaff();
        $seedingCampaign = SeedingCampaign::factory()->create(['status' => SeedingCampaignStatus::Active]);

        $this->get('/crm/seeding/'.$seedingCampaign->id)
            ->assertOk()
            ->assertDontSee('Finish setting up');
    }

    public function test_overview_shows_the_run_progress_pipeline_and_a_results_teaser(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $run = SeedingCampaign::factory()->create(['status' => SeedingCampaignStatus::Shipping]);
        $run->creators()->attach($creator->id);
        Shipment::factory()->create([
            'seeding_campaign_id' => $run->id,
            'creator_id' => $creator->id,
            'status' => ShipmentStatus::Delivered,
        ]);

        $this->get('/crm/seeding/'.$run->id)
            ->assertOk()
            // Pipeline card + its stages.
            ->assertSee('Run progress')
            ->assertSee('on the roster')
            ->assertSee('Delivered')
            // Results teaser + its jump to the full Results tab.
            ->assertSee('Results so far')
            ->assertSee('View full results');
    }
}
