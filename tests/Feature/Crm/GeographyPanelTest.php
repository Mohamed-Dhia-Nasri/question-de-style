<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Creators\GeographyPanel;
use App\Modules\CRM\Models\Creator;
use App\Modules\Discovery\Models\GeoAttribution;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Operator-assigned geography panel (ADR-0018) on the creator profile.
 * View needs the creator view; assigning/clearing re-authorizes update
 * (crm.manage); all writes ride the M2-owned CreatorGeography seam.
 */
class GeographyPanelTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_assigning_and_clearing_geography_rides_the_seam(): void
    {
        $this->actingAsCrmStaff();
        $creator = Creator::factory()->create();

        Livewire::test(GeographyPanel::class, ['creator' => $creator])
            ->assertSee('No geography assigned')
            ->set('geo_country', 'de')
            ->set('geo_region', 'Bavaria')
            ->set('geo_city', 'Munich')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('DE')
            ->assertSee('Munich');

        $row = GeoAttribution::query()->where('creator_id', $creator->id)->sole();
        $this->assertSame('DE', $row->country_code);
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator_geography.assigned', 'subject_id' => $row->id]);

        // Blank country withdraws the assignment entirely.
        Livewire::test(GeographyPanel::class, ['creator' => $creator])
            ->set('geo_country', '')
            ->set('geo_region', '')
            ->set('geo_city', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('No geography assigned');

        $this->assertDatabaseMissing('geo_attributions', ['creator_id' => $creator->id]);
    }

    public function test_region_or_city_without_a_country_is_refused(): void
    {
        $this->actingAsCrmStaff();
        $creator = Creator::factory()->create();

        Livewire::test(GeographyPanel::class, ['creator' => $creator])
            ->set('geo_city', 'Munich')
            ->call('save')
            ->assertHasErrors(['geo_country']);

        Livewire::test(GeographyPanel::class, ['creator' => $creator])
            ->set('geo_country', 'Germany') // not ISO-2
            ->call('save')
            ->assertHasErrors(['geo_country']);

        $this->assertDatabaseCount('geo_attributions', 0);
    }

    public function test_assigning_requires_crm_manage_not_just_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $creator = Creator::factory()->create();

        // The panel renders for a viewer — but the mutator refuses, even
        // when called directly with pre-set state.
        Livewire::test(GeographyPanel::class, ['creator' => $creator])
            ->set('geo_country', 'DE')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseCount('geo_attributions', 0);
    }
}
