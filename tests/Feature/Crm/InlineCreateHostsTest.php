<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Products\ProductsIndex;
use App\Modules\CRM\Livewire\Seeding\SeedingCampaignsIndex;
use App\Modules\CRM\Livewire\Seeding\ShipmentsPanel;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Inline "+ create" (CRM UX Stage C, F01b) reaching the remaining parent
 * selects — seeding run (brand/product/campaign), product (brand), and
 * shipment (product). WithInlineCreate itself is exercised by
 * tests/Feature/Crm/InlineCreateTest.php against BrandsIndex/CampaignsIndex;
 * these tests cover only the three new hosts' wiring.
 */
class InlineCreateHostsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    // --- SeedingCampaignsIndex ---------------------------------------------

    public function test_seeding_form_inline_brand_is_created_and_resets_product_and_campaign(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->set('seeding_product_id', '999')
            ->set('seeding_campaign_id', '888')
            ->call('openInlineCreate', 'brand')
            ->set('inline_new_client', true)
            ->set('inline_client_name', 'Neue Agentur')
            ->set('inline_brand_name', 'Atelier Nord')
            ->call('saveInlineCreate')
            ->assertHasNoErrors()
            ->assertSet('seeding_brand_id', (string) Brand::query()->where('name', 'Atelier Nord')->firstOrFail()->id)
            ->assertSet('seeding_product_id', '')
            ->assertSet('seeding_campaign_id', '');
    }

    public function test_seeding_form_inline_product_is_created_under_the_chosen_brand(): void
    {
        $this->actingAsCrmStaff();
        $brand = Brand::factory()->create();

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->set('seeding_brand_id', (string) $brand->id)
            ->call('openInlineCreate', 'product')
            ->set('inline_product_name', 'Sample Kit')
            ->call('saveInlineCreate')
            ->assertHasNoErrors();

        $product = Product::query()->where('name', 'Sample Kit')->firstOrFail();
        $this->assertSame($brand->id, $product->brand_id);
    }

    public function test_seeding_form_inline_product_requires_a_brand_first(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->call('openInlineCreate', 'product')
            ->assertSet('inlineCreate', null)
            ->assertDispatched('notify', type: 'error');
    }

    public function test_seeding_form_inline_campaign_starts_as_draft_under_the_chosen_brand(): void
    {
        $this->actingAsCrmStaff();
        $brand = Brand::factory()->create();

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->set('seeding_brand_id', (string) $brand->id)
            ->call('openInlineCreate', 'campaign')
            ->set('inline_campaign_name', 'Herbst Push')
            ->call('saveInlineCreate')
            ->assertHasNoErrors()
            ->assertSet('seeding_campaign_id', (string) Campaign::query()->where('name', 'Herbst Push')->firstOrFail()->id);

        $campaign = Campaign::query()->where('name', 'Herbst Push')->firstOrFail();
        $this->assertSame(CampaignStatus::Draft, $campaign->status);
        $this->assertSame($brand->id, $campaign->brand_id);
    }

    public function test_seeding_form_escape_closes_only_the_inline_form(): void
    {
        $this->actingAsCrmStaff();
        $brand = Brand::factory()->create();

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->set('seeding_brand_id', (string) $brand->id)
            ->call('openInlineCreate', 'product')
            ->call('cancelForm') // what both Escape handlers reach first
            ->assertSet('inlineCreate', null)
            ->assertSet('showForm', true);
    }

    // --- ProductsIndex -------------------------------------------------------

    public function test_products_form_inline_brand_is_created_and_selected(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(ProductsIndex::class)
            ->call('create')
            ->call('openInlineCreate', 'brand')
            ->set('inline_new_client', true)
            ->set('inline_client_name', 'Neue Agentur')
            ->set('inline_brand_name', 'Atelier Nord')
            ->call('saveInlineCreate')
            ->assertHasNoErrors()
            ->assertSet('product_brand_id', (string) Brand::query()->where('name', 'Atelier Nord')->firstOrFail()->id)
            ->assertDispatched('notify', type: 'success');
    }

    public function test_products_form_escape_closes_only_the_inline_form(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(ProductsIndex::class)
            ->call('create')
            ->call('openInlineCreate', 'brand')
            ->call('cancelForm')
            ->assertSet('inlineCreate', null)
            ->assertSet('showForm', true);
    }

    // --- ShipmentsPanel --------------------------------------------------------

    public function test_shipment_form_inline_product_lands_under_the_runs_brand(): void
    {
        $this->actingAsCrmStaff();
        $run = SeedingCampaign::factory()->create();

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $run])
            ->call('create')
            ->call('openInlineCreate', 'product')
            ->set('inline_product_name', 'Gift Box')
            ->call('saveInlineCreate')
            ->assertHasNoErrors()
            ->assertSet('shipment_product_id', (string) Product::query()->where('name', 'Gift Box')->firstOrFail()->id);

        $this->assertSame($run->brand_id, Product::query()->where('name', 'Gift Box')->firstOrFail()->brand_id);
    }

    public function test_shipment_form_escape_closes_only_the_inline_form(): void
    {
        $this->actingAsCrmStaff();
        $run = SeedingCampaign::factory()->create();

        Livewire::test(ShipmentsPanel::class, ['seedingCampaign' => $run])
            ->call('create')
            ->call('openInlineCreate', 'product')
            ->call('cancelForm')
            ->assertSet('inlineCreate', null)
            ->assertSet('showForm', true);
    }
}
