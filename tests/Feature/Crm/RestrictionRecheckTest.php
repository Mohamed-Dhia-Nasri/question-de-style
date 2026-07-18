<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Creators\BrandPreferencesPanel;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Item 5c: newly ADDING a restriction never auto-detaches the creator from
 * any roster it is already on — it only warns the operator which rosters
 * are for a brand that now matches (name or alias), so a human decides.
 * The preference is always saved, warning or not.
 */
class RestrictionRecheckTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_adding_a_restriction_matching_a_rostered_brand_warns_with_the_roster_name(): void
    {
        $this->actingAsCrmStaff();

        $brand = Brand::factory()->create(['name' => 'Rival Corp']);
        $creator = Creator::factory()->create();
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id, 'name' => 'Summer Push']);
        $campaign->creators()->syncWithoutDetaching([$creator->id]);

        Livewire::test(BrandPreferencesPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('preference_restricted', 'Rival Corp')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('notify', function (string $event, array $params) {
                return $params['type'] === 'error'
                    && str_contains($params['message'], 'Heads up')
                    && str_contains($params['message'], 'Summer Push');
            });

        $this->assertSame(['Rival Corp'], $creator->brandPreferences()->sole()->restricted_brands);
    }

    public function test_adding_an_unrelated_restriction_does_not_warn(): void
    {
        $this->actingAsCrmStaff();

        $brand = Brand::factory()->create(['name' => 'Rival Corp']);
        $creator = Creator::factory()->create();
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id, 'name' => 'Summer Push']);
        $campaign->creators()->syncWithoutDetaching([$creator->id]);

        Livewire::test(BrandPreferencesPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('preference_restricted', 'Totally Unrelated Brand')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('notify', function (string $event, array $params) {
                return $params['type'] === 'success';
            });

        $this->assertSame(['Totally Unrelated Brand'], $creator->brandPreferences()->sole()->restricted_brands);
    }

    public function test_alias_only_match_also_warns(): void
    {
        $this->actingAsCrmStaff();

        $brand = Brand::factory()->create(['name' => 'Aurelia', 'aliases' => ['Aurelia Cosmetics', 'AC Beauty']]);
        $creator = Creator::factory()->create();
        $seedingCampaign = SeedingCampaign::factory()->create(['brand_id' => $brand->id, 'name' => 'Gifting Wave']);
        $seedingCampaign->creators()->syncWithoutDetaching([$creator->id]);

        Livewire::test(BrandPreferencesPanel::class, ['creator' => $creator])
            ->call('add')
            // Restrict by alias, not the canonical brand name.
            ->set('preference_restricted', 'AC Beauty')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('notify', function (string $event, array $params) {
                return $params['type'] === 'error'
                    && str_contains($params['message'], 'Heads up')
                    && str_contains($params['message'], 'Gifting Wave');
            });

        $this->assertSame(['AC Beauty'], $creator->brandPreferences()->sole()->restricted_brands);
    }

    public function test_editing_a_preference_to_add_a_new_restriction_also_warns_but_keeps_the_old_one_saved(): void
    {
        $this->actingAsCrmStaff();

        $brand = Brand::factory()->create(['name' => 'Rival Corp']);
        $creator = Creator::factory()->create();
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id, 'name' => 'Summer Push']);
        $campaign->creators()->syncWithoutDetaching([$creator->id]);

        $preference = $creator->brandPreferences()->create(['restricted_brands' => ['Already Restricted']]);

        Livewire::test(BrandPreferencesPanel::class, ['creator' => $creator])
            ->call('edit', $preference->id)
            ->set('preference_restricted', "Already Restricted\nRival Corp")
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('notify', function (string $event, array $params) {
                return $params['type'] === 'error' && str_contains($params['message'], 'Summer Push');
            });

        $this->assertSame(['Already Restricted', 'Rival Corp'], $preference->refresh()->restricted_brands);
    }

    public function test_crm_view_only_user_cannot_save(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $creator = Creator::factory()->create();

        Livewire::test(BrandPreferencesPanel::class, ['creator' => $creator])
            ->set('preference_restricted', 'Rival Corp')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseMissing('brand_preferences', ['creator_id' => $creator->id]);
    }
}
