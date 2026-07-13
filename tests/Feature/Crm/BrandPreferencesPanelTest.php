<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Creators\BrandPreferencesPanel;
use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Creator;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Brand preferences panel (REQ-M3-003): preferred/restricted brands are
 * plain string lists per the canonical ENT-BrandPreference shape — parsed
 * from one-name-per-line input, never brand FKs.
 */
class BrandPreferencesPanelTest extends TestCase
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

        Livewire::test(BrandPreferencesPanel::class, ['creator' => Creator::factory()->create()])
            ->assertForbidden();
    }

    public function test_an_operator_records_preferred_and_restricted_brand_name_lists(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();

        Livewire::test(BrandPreferencesPanel::class, ['creator' => $creator])
            ->call('add')
            ->set('preference_preferred', "Acme Beauty\n  Glow GmbH  \n")
            ->set('preference_restricted', 'Rival Corp')
            ->set('preference_notes', 'Exclusivity with Acme until Q4.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $preference = $creator->brandPreferences()->sole();

        // Line-parsed, trimmed, plain strings (canonical shape).
        $this->assertSame(['Acme Beauty', 'Glow GmbH'], $preference->preferred_brands);
        $this->assertSame(['Rival Corp'], $preference->restricted_brands);
        $this->assertSame('Exclusivity with Acme until Q4.', $preference->notes);
    }

    public function test_an_operator_edits_a_preference(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $preference = BrandPreference::factory()->create([
            'creator_id' => $creator->id,
            'preferred_brands' => ['Old Brand'],
        ]);

        Livewire::test(BrandPreferencesPanel::class, ['creator' => $creator])
            ->call('edit', $preference->id)
            ->assertSet('preference_preferred', 'Old Brand')
            ->set('preference_preferred', "New Brand\nSecond Brand")
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['New Brand', 'Second Brand'], $preference->refresh()->preferred_brands);
    }

    public function test_deletion_requires_confirmation(): void
    {
        $this->actingAsCrmStaff();

        $creator = Creator::factory()->create();
        $preference = BrandPreference::factory()->create(['creator_id' => $creator->id]);

        Livewire::test(BrandPreferencesPanel::class, ['creator' => $creator])
            ->call('confirmDelete', $preference->id)
            ->assertSet('confirmingDeleteId', $preference->id)
            ->call('delete');

        $this->assertDatabaseMissing('brand_preferences', ['id' => $preference->id]);
    }

    public function test_mutating_actions_require_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        // A user holding ONLY crm.view can mount the panel, but every
        // mutating action re-authorizes server-side against crm.manage.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $creator = Creator::factory()->create();
        $preference = BrandPreference::factory()->create(['creator_id' => $creator->id]);

        Livewire::test(BrandPreferencesPanel::class, ['creator' => $creator])->assertOk()
            ->call('add')->assertForbidden();
        Livewire::test(BrandPreferencesPanel::class, ['creator' => $creator])
            ->call('confirmDelete', $preference->id)->assertForbidden();

        // The persisting mutators re-authorize themselves even when the
        // gated form-open actions are bypassed via direct state writes.
        Livewire::test(BrandPreferencesPanel::class, ['creator' => $creator])
            ->set('preference_notes', 'smuggled note')
            ->call('save')->assertForbidden();
        Livewire::test(BrandPreferencesPanel::class, ['creator' => $creator])
            ->set('confirmingDeleteId', $preference->id)
            ->call('delete')->assertForbidden();

        $this->assertDatabaseMissing('brand_preferences', ['notes' => 'smuggled note']);
        $this->assertDatabaseHas('brand_preferences', ['id' => $preference->id]);
    }
}
