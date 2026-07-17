<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Documents\DocumentsPanel;
use App\Modules\CRM\Livewire\Results\SeedingResultsPanel;
use App\Modules\CRM\Livewire\Seeding\SeedingCreatorsPanel;
use App\Modules\CRM\Livewire\Seeding\ShipmentsPanel;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SeedingCampaignStatus;
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
}
