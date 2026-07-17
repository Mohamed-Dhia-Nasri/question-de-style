<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Campaigns\CampaignCreatorsPanel;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Searchable multi-select roster picker on campaigns (CRM UX Stage C, F08):
 * pick many creators at once, restricted ones stay selectable but are
 * SKIPPED at save with a named notice, and a brand-new creator can be
 * created and added inline. The AC-M3-007 hard filter still enforces at
 * attach time via BrandRestrictionGuard::assertNotRestricted.
 */
class CampaignRosterPickerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    private function campaignForBrand(string $brandName): Campaign
    {
        $brand = Brand::factory()->create(['name' => $brandName]);

        return Campaign::factory()->create(['brand_id' => $brand->id]);
    }

    public function test_attach_selected_adds_allowed_and_skips_restricted_with_named_notice(): void
    {
        $this->actingAsCrmStaff();
        // Deterministic brand name (no random faker collisions, no case-fold
        // round-trip surprises) — the whole point of the port fix.
        $campaign = $this->campaignForBrand('Aurelia Cosmetics');
        $ok = Creator::factory()->create(['display_name' => 'Ariane Förster']);
        $blocked = Creator::factory()->create(['display_name' => 'Cordula Blank']);
        BrandPreference::factory()->create([
            'creator_id' => $blocked->id,
            'restricted_brands' => [$campaign->brand->name],
        ]);

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('openPicker')
            ->set('selectedCreatorIds', [(string) $ok->id, (string) $blocked->id])
            ->call('attachSelected')
            ->assertDispatched('notify', type: 'success');

        $this->assertTrue($campaign->creators()->whereKey($ok->id)->exists());
        $this->assertFalse($campaign->creators()->whereKey($blocked->id)->exists());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'campaign_creator.attached',
            'subject_id' => $campaign->id,
        ]);
    }

    public function test_when_every_pick_is_restricted_it_notifies_an_error(): void
    {
        $this->actingAsCrmStaff();
        $campaign = $this->campaignForBrand('Aurelia Cosmetics');
        $blocked = Creator::factory()->create();
        BrandPreference::factory()->create([
            'creator_id' => $blocked->id,
            'restricted_brands' => [$campaign->brand->name],
        ]);

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('openPicker')
            ->set('selectedCreatorIds', [(string) $blocked->id])
            ->call('attachSelected')
            ->assertDispatched('notify', type: 'error');

        $this->assertFalse($campaign->creators()->whereKey($blocked->id)->exists());
    }

    public function test_the_picker_flags_a_restricted_candidate_and_renders_the_footer(): void
    {
        $this->actingAsCrmStaff();
        $campaign = $this->campaignForBrand('Lumière Skincare');
        Creator::factory()->create(['display_name' => 'Restricted Romy']);
        $restricted = Creator::query()->where('display_name', 'Restricted Romy')->firstOrFail();
        BrandPreference::factory()->create([
            'creator_id' => $restricted->id,
            'restricted_brands' => ['Lumière Skincare'],
        ]);

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('openPicker')
            ->assertSee('Restricted Romy')
            ->assertSee('On their no-go list')
            ->assertSee('Add selected')
            ->assertSee('0 selected');
    }

    public function test_a_blocklisted_candidate_is_skipped_at_attach(): void
    {
        $this->actingAsCrmStaff();
        $campaign = $this->campaignForBrand('Aurelia Cosmetics');
        $blocked = Creator::factory()->create([
            'display_name' => 'Blocklisted Bea',
            'relationship_status' => RelationshipStatus::Blocklisted,
        ]);

        // Still shown and selectable in the picker (the checkbox row renders),
        // and flagged with its "do not contact" note…
        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('openPicker')
            ->assertSee('Blocklisted Bea')
            ->assertSee('do not contact or book')
            ->assertSeeHtml('wire:key="picker-'.$blocked->id.'"')
            // …but selecting and adding it skips it with a "do not contact"
            // notice — item 5b flips F06 from flag-only to enforced.
            ->set('selectedCreatorIds', [(string) $blocked->id])
            ->call('attachSelected')
            ->assertDispatched(
                'notify',
                fn (string $event, array $params) => str_contains($params['message'], 'do not contact'),
            );

        $this->assertFalse($campaign->creators()->whereKey($blocked->id)->exists());
    }

    public function test_search_narrows_the_candidate_list(): void
    {
        $this->actingAsCrmStaff();
        $campaign = $this->campaignForBrand('Aurelia Cosmetics');
        Creator::factory()->create(['display_name' => 'Zephyrine Aalborg']);
        Creator::factory()->create(['display_name' => 'Bruno Vandenberg']);

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('openPicker')
            ->set('rosterSearch', 'Zephyrine')
            ->assertSee('Zephyrine Aalborg')
            ->assertDontSee('Bruno Vandenberg');
    }

    public function test_creators_already_on_the_roster_are_not_offered_as_candidates(): void
    {
        $this->actingAsCrmStaff();
        $campaign = $this->campaignForBrand('Aurelia Cosmetics');
        $onRoster = Creator::factory()->create(['display_name' => 'Already Anneke']);
        $campaign->creators()->attach($onRoster->id);
        $fresh = Creator::factory()->create(['display_name' => 'Fresh Fenna']);

        // The on-roster creator still shows in the participating list, so
        // assert on the picker-row key rather than the display name.
        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('openPicker')
            ->assertSeeHtml('wire:key="picker-'.$fresh->id.'"')
            ->assertDontSeeHtml('wire:key="picker-'.$onRoster->id.'"');
    }

    public function test_platform_filter_limits_candidates_to_that_platform(): void
    {
        $this->actingAsCrmStaff();
        $campaign = $this->campaignForBrand('Aurelia Cosmetics');
        $insta = Creator::factory()->create(['display_name' => 'Insta Only Fatima']);
        $insta->platformAccounts()->save(
            PlatformAccount::factory()->make(['platform' => Platform::Instagram])
        );
        $tiktok = Creator::factory()->create(['display_name' => 'Tik Only Gustav']);
        $tiktok->platformAccounts()->save(
            PlatformAccount::factory()->make(['platform' => Platform::TikTok])
        );

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('openPicker')
            ->set('rosterPlatform', Platform::Instagram->value)
            ->assertSee('Insta Only Fatima')
            ->assertDontSee('Tik Only Gustav');
    }

    public function test_attach_selected_with_no_selection_shows_a_validation_error(): void
    {
        $this->actingAsCrmStaff();
        $campaign = $this->campaignForBrand('Aurelia Cosmetics');

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('openPicker')
            ->call('attachSelected')
            ->assertHasErrors('selectedCreatorIds');
    }

    public function test_a_view_only_user_cannot_open_the_picker(): void
    {
        $this->seedRoles();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $campaign = Campaign::factory()->create();

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('openPicker')
            ->assertForbidden();
    }

    public function test_create_and_attach_creates_enrolls_and_attaches_the_creator(): void
    {
        $this->actingAsCrmStaff();
        $campaign = $this->campaignForBrand('Aurelia Cosmetics');

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('openPicker')
            ->set('showNewCreatorForm', true)
            ->set('new_creator_name', 'Neele Sonnenschein')
            ->set('new_creator_language', 'de')
            ->call('createAndAttachCreator')
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'success');

        $creator = Creator::query()->where('display_name', 'Neele Sonnenschein')->firstOrFail();
        $this->assertTrue($campaign->creators()->whereKey($creator->id)->exists());
        // CreatorWriter auto-enrolls the new creator into monitoring in the
        // same transaction — a MonitoredSubject must now exist for it.
        $this->assertTrue(MonitoredSubject::query()->where('creator_id', $creator->id)->exists());
        $this->assertDatabaseHas('audit_logs', ['action' => 'creator.created', 'subject_id' => $creator->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'campaign_creator.attached',
            'subject_id' => $campaign->id,
        ]);
    }

    public function test_create_and_attach_requires_a_name(): void
    {
        $this->actingAsCrmStaff();
        $campaign = $this->campaignForBrand('Aurelia Cosmetics');

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('openPicker')
            ->set('showNewCreatorForm', true)
            ->set('new_creator_name', '')
            ->call('createAndAttachCreator')
            ->assertHasErrors('new_creator_name');
    }

    public function test_a_foreign_tenant_creator_in_the_selection_is_rejected(): void
    {
        $this->actingAsCrmStaff();
        $campaign = $this->campaignForBrand('Aurelia Cosmetics');

        $foreign = $this->withTenant(
            $this->makeTenant('Tenant B'),
            fn () => Creator::factory()->create(['display_name' => 'Foreign Franka']),
        );

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('openPicker')
            ->set('selectedCreatorIds', [(string) $foreign->id])
            ->call('attachSelected')
            ->assertHasErrors('selectedCreatorIds');

        $this->assertFalse($campaign->creators()->whereKey($foreign->id)->exists());
    }
}
