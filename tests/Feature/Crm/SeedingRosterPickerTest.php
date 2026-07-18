<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Seeding\SeedingCreatorsPanel;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Seeding runs get the same searchable multi-select roster picker as
 * campaigns (CRM UX Stage C, F08/F04) via ManagesCreatorRoster, checked
 * against the RUN's brand (not the parent campaign's — they usually match,
 * but the guard must read the right one). Runs spawned from a campaign also
 * get a one-click "copy campaign roster" shortcut.
 */
class SeedingRosterPickerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    private function seedingForBrand(string $brandName): SeedingCampaign
    {
        $brand = Brand::factory()->create(['name' => $brandName]);

        return SeedingCampaign::factory()->create(['brand_id' => $brand->id]);
    }

    public function test_attach_selected_adds_allowed_and_skips_restricted_against_the_runs_brand(): void
    {
        $this->actingAsCrmStaff();
        // Deterministic brand name — no random faker collisions, no
        // case-fold round-trip surprises (Task 6's ß lesson).
        $run = $this->seedingForBrand('Aurelia Cosmetics');
        $ok = Creator::factory()->create(['display_name' => 'Ariane Förster']);
        $blocked = Creator::factory()->create(['display_name' => 'Cordula Blank']);
        BrandPreference::factory()->create([
            'creator_id' => $blocked->id,
            'restricted_brands' => [$run->brand->name],
        ]);

        Livewire::test(SeedingCreatorsPanel::class, ['seedingCampaign' => $run])
            ->call('openPicker')
            ->set('selectedCreatorIds', [(string) $ok->id, (string) $blocked->id])
            ->call('attachSelected')
            ->assertDispatched('notify', type: 'success');

        $this->assertTrue($run->creators()->whereKey($ok->id)->exists());
        $this->assertFalse($run->creators()->whereKey($blocked->id)->exists());
        $this->assertDatabaseHas('seeding_campaign_creator', [
            'seeding_campaign_id' => $run->id,
            'creator_id' => $ok->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'seeding_campaign_creator.attached',
            'subject_id' => $run->id,
        ]);
    }

    public function test_a_blocklisted_candidate_is_skipped_at_attach(): void
    {
        $this->actingAsCrmStaff();
        $run = $this->seedingForBrand('Aurelia Cosmetics');
        $blocked = Creator::factory()->create([
            'display_name' => 'Blocklisted Bea',
            'relationship_status' => RelationshipStatus::Blocklisted,
        ]);

        // Still shown and selectable in the picker, flagged with its
        // "do not contact" note…
        Livewire::test(SeedingCreatorsPanel::class, ['seedingCampaign' => $run])
            ->call('openPicker')
            ->assertSee('Blocklisted Bea')
            ->assertSee('do not contact or book')
            ->assertSeeHtml('wire:key="picker-'.$blocked->id.'"')
            // …but adding it skips it with a "do not contact" notice (item 5b
            // flips F06 from flag-only to enforced on the seeding path too).
            ->set('selectedCreatorIds', [(string) $blocked->id])
            ->call('attachSelected')
            ->assertDispatched(
                'notify',
                fn (string $event, array $params) => str_contains($params['message'], 'do not contact'),
            );

        $this->assertFalse($run->creators()->whereKey($blocked->id)->exists());
    }

    public function test_a_view_only_user_cannot_open_the_picker(): void
    {
        $this->seedRoles();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $run = SeedingCampaign::factory()->create();

        Livewire::test(SeedingCreatorsPanel::class, ['seedingCampaign' => $run])
            ->call('openPicker')
            ->assertForbidden();
    }

    public function test_copy_campaign_roster_adds_allowed_skips_restricted_and_counts_already_attached(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();
        $run = SeedingCampaign::factory()->forCampaign($campaign)->create(['brand_id' => $campaign->brand_id]);

        [$a, $b, $c] = Creator::factory()->count(3)->create();
        $campaign->creators()->syncWithoutDetaching([$a->id, $b->id, $c->id]);
        $run->creators()->syncWithoutDetaching([$a->id]); // already on the run
        BrandPreference::factory()->create(['creator_id' => $b->id, 'restricted_brands' => [$campaign->brand->name]]);

        Livewire::test(SeedingCreatorsPanel::class, ['seedingCampaign' => $run->fresh()])
            ->call('copyCampaignRoster')
            ->assertDispatched('notify', type: 'success');

        $this->assertTrue($run->creators()->whereKey($c->id)->exists());   // added
        $this->assertFalse($run->creators()->whereKey($b->id)->exists());  // restricted skipped
        $this->assertSame(2, $run->creators()->count());                   // a + c
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'seeding_campaign_creator.attached',
            'subject_id' => $run->id,
        ]);
    }

    public function test_copy_campaign_roster_notifies_when_there_is_no_parent_campaign(): void
    {
        $this->actingAsCrmStaff();
        $run = SeedingCampaign::factory()->create(); // campaign_id null

        Livewire::test(SeedingCreatorsPanel::class, ['seedingCampaign' => $run])
            ->call('copyCampaignRoster')
            ->assertDispatched('notify', type: 'error', message: 'This run has no parent campaign.');

        $this->assertSame(0, $run->creators()->count());
    }

    public function test_copy_button_absent_on_standalone_runs(): void
    {
        $this->actingAsCrmStaff();
        $run = SeedingCampaign::factory()->create(); // campaign_id null

        Livewire::test(SeedingCreatorsPanel::class, ['seedingCampaign' => $run])
            ->assertDontSee('Copy campaign roster');
    }

    public function test_copy_campaign_roster_is_forbidden_for_view_only_users(): void
    {
        $this->seedRoles();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $campaign = Campaign::factory()->create();
        $run = SeedingCampaign::factory()->forCampaign($campaign)->create(['brand_id' => $campaign->brand_id]);
        $creator = Creator::factory()->create();
        $campaign->creators()->syncWithoutDetaching([$creator->id]);

        Livewire::test(SeedingCreatorsPanel::class, ['seedingCampaign' => $run])
            ->call('copyCampaignRoster')
            ->assertForbidden();

        $this->assertSame(0, $run->creators()->count());
    }
}
