<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Seeding\SeedingCreatorsPanel;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Seeding participation (seeding_campaign_creator pivot). Adding creators is
 * the searchable multi-select picker (see SeedingRosterPickerTest); this
 * file covers the panel shell: page render, view authorization, and the
 * shipment-guarded detach flow (see SeedingRosterRepairTest for the
 * blocked-by-shipments case).
 */
class SeedingCreatorsPanelTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_the_seeding_detail_page_renders_the_panel_and_viewers_are_refused(): void
    {
        $this->actingAsCrmStaff();

        $seeding = SeedingCampaign::factory()->create();

        $this->get('/crm/seeding/'.$seeding->id)
            ->assertOk()
            ->assertSeeLivewire(SeedingCreatorsPanel::class);

        $this->actingAs($this->makeUser(RoleName::ClientViewer));
        $this->get('/crm/seeding/'.$seeding->id)->assertForbidden();
        Livewire::test(SeedingCreatorsPanel::class, ['seedingCampaign' => $seeding])->assertForbidden();
    }

    public function test_detaching_removes_the_pivot(): void
    {
        $this->actingAsCrmStaff();

        $seeding = SeedingCampaign::factory()->create();
        $creator = Creator::factory()->create();
        $seeding->creators()->attach($creator->id);

        Livewire::test(SeedingCreatorsPanel::class, ['seedingCampaign' => $seeding])
            ->call('confirmDetach', $creator->id)
            ->call('detach');

        $this->assertDatabaseMissing('seeding_campaign_creator', [
            'seeding_campaign_id' => $seeding->id,
            'creator_id' => $creator->id,
        ]);
    }

    public function test_mutating_actions_require_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $seeding = SeedingCampaign::factory()->create();
        $creator = Creator::factory()->create();
        $seeding->creators()->attach($creator->id);

        Livewire::test(SeedingCreatorsPanel::class, ['seedingCampaign' => $seeding])->assertOk()
            ->call('openPicker')->assertForbidden();
        Livewire::test(SeedingCreatorsPanel::class, ['seedingCampaign' => $seeding])
            ->set('confirmingDetachId', $creator->id)
            ->call('detach')->assertForbidden();

        $this->assertSame(1, $seeding->creators()->count());
    }
}
