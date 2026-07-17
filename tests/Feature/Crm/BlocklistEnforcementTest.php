<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Campaigns\CampaignCreatorsPanel;
use App\Modules\CRM\Livewire\Campaigns\CampaignWizard;
use App\Modules\CRM\Livewire\Seeding\SeedingCreatorsPanel;
use App\Modules\CRM\Livewire\Seeding\ShipmentsPanel;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\ShipmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Item 5b — the "do not contact or book" status finally blocks. A
 * RelationshipStatus::Blocklisted creator stays visible/selectable in the
 * picker but is kept off every roster: the three bulk paths SOFT-SKIP them
 * with a named notice, while a single shipment recipient is HARD-BLOCKED
 * with a validation error. The asymmetry is intentional.
 */
class BlocklistEnforcementTest extends TestCase
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

    private function blocklisted(string $name): Creator
    {
        return Creator::factory()->create([
            'display_name' => $name,
            'relationship_status' => RelationshipStatus::Blocklisted,
        ]);
    }

    public function test_bulk_attach_skips_a_blocklisted_creator_with_a_do_not_contact_notice(): void
    {
        $this->actingAsCrmStaff();
        $campaign = $this->campaignForBrand('Aurelia Cosmetics');
        $ok = Creator::factory()->create(['display_name' => 'Ariane Förster']);
        $blocked = $this->blocklisted('Blocklisted Bea');

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('openPicker')
            ->set('selectedCreatorIds', [(string) $ok->id, (string) $blocked->id])
            ->call('attachSelected')
            ->assertDispatched(
                'notify',
                fn (string $event, array $params) => $params['type'] === 'success'
                    && str_contains($params['message'], 'do not contact')
                    && str_contains($params['message'], 'Blocklisted Bea'),
            );

        $this->assertTrue($campaign->creators()->whereKey($ok->id)->exists());
        $this->assertFalse($campaign->creators()->whereKey($blocked->id)->exists());
    }

    public function test_bulk_attach_composes_both_skip_reasons_when_restricted_and_blocklisted_coexist(): void
    {
        $this->actingAsCrmStaff();
        $campaign = $this->campaignForBrand('Aurelia Cosmetics');
        $ok = Creator::factory()->create(['display_name' => 'Ariane Förster']);
        $restricted = Creator::factory()->create(['display_name' => 'Restricted Romy']);
        BrandPreference::factory()->create([
            'creator_id' => $restricted->id,
            'restricted_brands' => [$campaign->brand->name],
        ]);
        $blocked = $this->blocklisted('Blocklisted Bea');

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('openPicker')
            ->set('selectedCreatorIds', [(string) $ok->id, (string) $restricted->id, (string) $blocked->id])
            ->call('attachSelected')
            ->assertDispatched(
                'notify',
                fn (string $event, array $params) => $params['type'] === 'success'
                    && str_contains($params['message'], 'brand restrictions')
                    && str_contains($params['message'], 'do not contact'),
            );

        $this->assertTrue($campaign->creators()->whereKey($ok->id)->exists());
        $this->assertFalse($campaign->creators()->whereKey($restricted->id)->exists());
        $this->assertFalse($campaign->creators()->whereKey($blocked->id)->exists());
    }

    public function test_a_creator_that_is_both_restricted_and_blocklisted_is_skipped(): void
    {
        $this->actingAsCrmStaff();
        $campaign = $this->campaignForBrand('Aurelia Cosmetics');
        $both = $this->blocklisted('Both Barrier');
        BrandPreference::factory()->create([
            'creator_id' => $both->id,
            'restricted_brands' => [$campaign->brand->name],
        ]);

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('openPicker')
            ->set('selectedCreatorIds', [(string) $both->id])
            ->call('attachSelected')
            ->assertDispatched('notify', type: 'error');

        $this->assertFalse($campaign->creators()->whereKey($both->id)->exists());
    }

    public function test_a_plain_creator_still_attaches(): void
    {
        $this->actingAsCrmStaff();
        $campaign = $this->campaignForBrand('Aurelia Cosmetics');
        $ok = Creator::factory()->create(['display_name' => 'Ariane Förster']);

        Livewire::test(CampaignCreatorsPanel::class, ['campaign' => $campaign])
            ->call('openPicker')
            ->set('selectedCreatorIds', [(string) $ok->id])
            ->call('attachSelected')
            ->assertDispatched('notify', type: 'success');

        $this->assertTrue($campaign->creators()->whereKey($ok->id)->exists());
    }

    public function test_copy_campaign_roster_skips_a_blocklisted_creator(): void
    {
        $this->actingAsCrmStaff();
        $campaign = Campaign::factory()->create();
        $run = SeedingCampaign::factory()->forCampaign($campaign)->create(['brand_id' => $campaign->brand_id]);

        $ok = Creator::factory()->create(['display_name' => 'Ariane Förster']);
        $blocked = $this->blocklisted('Blocklisted Bea');
        $campaign->creators()->syncWithoutDetaching([$ok->id, $blocked->id]);

        Livewire::test(SeedingCreatorsPanel::class, ['seedingCampaign' => $run->fresh()])
            ->call('copyCampaignRoster')
            ->assertDispatched(
                'notify',
                fn (string $event, array $params) => $params['type'] === 'success'
                    && str_contains($params['message'], 'do not contact'),
            );

        $this->assertTrue($run->creators()->whereKey($ok->id)->exists());
        $this->assertFalse($run->creators()->whereKey($blocked->id)->exists());
        $this->assertSame(1, $run->creators()->count());
    }

    public function test_the_wizard_skips_a_blocklisted_creator_and_reports_the_name_on_the_done_screen(): void
    {
        $this->actingAsCrmStaff();
        $ok = Creator::factory()->create(['display_name' => 'Greta Good']);
        $blocked = $this->blocklisted('Nora NoContact');

        Livewire::test(CampaignWizard::class)
            ->set('client_mode', 'new')->set('new_client_name', 'Brückner GmbH')
            ->set('brand_mode', 'new')->set('new_brand_name', 'Atelier Nord')
            ->call('next')
            ->set('campaign_name', 'Creator Week')->call('next')
            ->set('with_seeding', false)->call('next')
            ->set('selected_creator_ids', [(string) $ok->id, (string) $blocked->id])->call('next')
            ->call('finish')
            ->assertSet('finished', true)
            ->assertSee('Nora NoContact')
            // The Done-screen heading must not claim a brand no-go list when
            // the only reason a name is on it is the blocklist.
            ->assertSee('These creators were not added')
            ->assertDontSee('no-go list includes this brand');

        $campaign = Campaign::query()->where('name', 'Creator Week')->firstOrFail();
        $this->assertTrue($campaign->creators()->whereKey($ok->id)->exists());
        $this->assertFalse($campaign->creators()->whereKey($blocked->id)->exists());
    }

    public function test_a_shipment_to_a_blocklisted_recipient_is_hard_blocked(): void
    {
        $this->actingAsCrmStaff();
        $brand = Brand::factory()->create();
        $run = SeedingCampaign::factory()->create(['brand_id' => $brand->id]);
        $product = Product::factory()->create(['brand_id' => $brand->id]);
        $blocked = $this->blocklisted('Blocklisted Bea');

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $run])
            ->call('create')
            ->set('shipment_creator_id', (string) $blocked->id)
            ->set('shipment_product_id', (string) $product->id)
            ->set('shipment_status', ShipmentStatus::Pending->value)
            ->call('save')
            ->assertHasErrors(['shipment_creator_id']);

        $this->assertFalse($run->creators()->whereKey($blocked->id)->exists());
        $this->assertSame(0, Shipment::query()->where('seeding_campaign_id', $run->id)->count());
    }
}
