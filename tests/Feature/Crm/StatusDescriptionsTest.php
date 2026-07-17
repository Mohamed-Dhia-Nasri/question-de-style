<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Campaigns\CampaignsIndex;
use App\Modules\CRM\Livewire\Creators\CreatorProfile;
use App\Modules\CRM\Livewire\Creators\CreatorsIndex;
use App\Modules\CRM\Livewire\Seeding\SeedingCampaignsIndex;
use App\Modules\CRM\Livewire\Seeding\ShipmentsPanel;
use App\Modules\CRM\Livewire\Tasks\TasksIndex;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SeedingType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Task 8: every status/type select in the CRM forms carries a live
 * one-line plain-language meaning (Alpine x-data map, seeded from each
 * enum's description() and kept in sync with x-on:change). Also covers the
 * NULL/NONE relationship-status dash unification on the creators index
 * (F24): both render as the same "no relationship" dash, never a "None"
 * badge.
 */
class StatusDescriptionsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_campaign_status_select_carries_meaning_map(): void
    {
        $this->actingAsCrmStaff();

        // @js() JSON-encodes the map, which \u escapes the em dash and
        // quotes — assert the plain-ASCII tail of the description instead
        // of the full string (per the brief's documented fallback).
        Livewire::test(CampaignsIndex::class)
            ->call('create')
            ->assertSee('not counted in results yet', false);
    }

    public function test_seeding_status_and_type_selects_carry_meaning_maps(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            // Default seeding_status on create() is DRAFT.
            ->assertSee('add creators and a product first', false)
            // seeding_type starts unselected; picking one flips the map key live.
            ->set('seeding_type', SeedingType::Gifting->value)
            ->assertSee('Free product, no posting agreement', false);
    }

    public function test_shipment_status_select_carries_meaning_map(): void
    {
        $this->actingAsCrmStaff();

        $seeding = SeedingCampaign::factory()->create(['brand_id' => Brand::factory()->create()->id]);

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $seeding])
            ->call('create')
            // Default shipment_status on create() is PENDING.
            ->assertSee('Not prepared yet', false);
    }

    public function test_task_status_select_carries_meaning_map(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::CampaignManager));

        Livewire::test(TasksIndex::class)
            ->call('create')
            // Default task_status on create() is OPEN.
            ->assertSee('Not started', false);
    }

    public function test_creators_index_relationship_status_select_carries_meaning_map(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(CreatorsIndex::class)
            ->call('create')
            // Empty-value label reads "— none —", not "No status".
            ->assertSee('— none —')
            ->set('relationship_status', RelationshipStatus::Prospect->value)
            ->assertSee('no contact yet', false);
    }

    public function test_creator_profile_relationship_status_select_carries_meaning_map(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create(['relationship_status' => RelationshipStatus::Active]);

        Livewire::test(CreatorProfile::class, ['creator' => $creator])
            ->assertSee('— none —')
            // @js() renders the CURRENT value on mount — the description
            // for the creator's existing status shows without any change.
            ->assertSee('Currently working together', false);
    }

    public function test_creators_index_renders_the_same_dash_for_null_and_none_relationship_status(): void
    {
        $this->actingAsCrmStaff();

        Creator::factory()->create(['display_name' => 'Blank Slate Creator', 'relationship_status' => null]);
        Creator::factory()->create(['display_name' => 'Zero Stage Creator', 'relationship_status' => RelationshipStatus::None]);

        $html = Livewire::test(CreatorsIndex::class)
            ->assertSee('Blank Slate Creator')
            ->assertSee('Zero Stage Creator')
            ->html();

        // Both rows fall back to the same dash — neither renders a "None"
        // badge. The only legitimate occurrence of the label left on the
        // page is the relationship-status filter's own <option>.
        $this->assertSame(1, substr_count($html, 'None'));
    }
}
