<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Creators\ParticipationPanel;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Participation panel (Stage B, F05): what a creator is involved in —
 * campaigns, seeding runs, and shipments — each row linking onward. Reads
 * need crm.view; rows never leak between creators.
 */
class ParticipationPanelTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_client_viewers_cannot_mount_the_panel(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        Livewire::test(ParticipationPanel::class, ['creator' => Creator::factory()->create()])
            ->assertForbidden();
    }

    public function test_staff_sees_campaign_run_and_shipment_names_with_links(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();

        $campaign = Campaign::factory()->create(['name' => 'Summer Glow Push']);
        $campaign->creators()->attach($creator->id);

        $run = SeedingCampaign::factory()->create(['name' => 'Autumn Gifting Wave']);
        $run->creators()->attach($creator->id);

        $shipment = Shipment::factory()->create([
            'creator_id' => $creator->id,
            'seeding_campaign_id' => $run->id,
        ]);

        Livewire::test(ParticipationPanel::class, ['creator' => $creator])
            ->assertOk()
            ->assertSee('Summer Glow Push')
            ->assertSee(route('crm.campaigns.show', $campaign), false)
            ->assertSee('Autumn Gifting Wave')
            ->assertSee(route('crm.seeding.show', $run), false)
            ->assertSee($shipment->product->name);
    }

    public function test_a_foreign_creators_rows_never_leak(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $otherCreator = Creator::factory()->create();

        $ownCampaign = Campaign::factory()->create(['name' => 'Own Campaign Row']);
        $ownCampaign->creators()->attach($creator->id);

        $foreignCampaign = Campaign::factory()->create(['name' => 'Foreign Campaign Row']);
        $foreignCampaign->creators()->attach($otherCreator->id);

        $foreignRun = SeedingCampaign::factory()->create(['name' => 'Foreign Seeding Row']);
        $foreignRun->creators()->attach($otherCreator->id);

        Shipment::factory()->create([
            'creator_id' => $otherCreator->id,
            'seeding_campaign_id' => $foreignRun->id,
        ]);

        Livewire::test(ParticipationPanel::class, ['creator' => $creator])
            ->assertSee('Own Campaign Row')
            ->assertDontSee('Foreign Campaign Row')
            ->assertDontSee('Foreign Seeding Row');
    }

    public function test_empty_state_renders_when_the_creator_has_no_participation(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();

        Livewire::test(ParticipationPanel::class, ['creator' => $creator])
            ->assertSee('Not involved in anything yet');
    }

    public function test_shipments_list_is_capped_with_an_overflow_line(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $run = SeedingCampaign::factory()->create();
        $run->creators()->attach($creator->id);

        Shipment::factory()->count(12)->create([
            'creator_id' => $creator->id,
            'seeding_campaign_id' => $run->id,
        ]);

        Livewire::test(ParticipationPanel::class, ['creator' => $creator])
            ->assertSee('…and 2 more on the seeding run pages.');
    }

    public function test_mounting_requires_crm_view(): void
    {
        $this->seedRoles();

        $userWithoutCrm = User::factory()->create();
        $this->actingAs($userWithoutCrm);

        Livewire::test(ParticipationPanel::class, ['creator' => Creator::factory()->create()])
            ->assertForbidden();

        $userWithoutCrm->givePermissionTo(PermissionsCatalog::CRM_VIEW);

        Livewire::test(ParticipationPanel::class, ['creator' => Creator::factory()->create()])
            ->assertOk();
    }
}
