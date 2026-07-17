<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Brands\BrandDetail;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BrandDetailTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_page_renders_the_component_with_all_three_lists(): void
    {
        $this->actingAsCrmStaff();
        $brand = Brand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'Serum Eins']);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id, 'name' => 'Kampagne Eins']);
        $run = SeedingCampaign::factory()->create(['brand_id' => $brand->id, 'name' => 'Run Eins']);

        $this->get('/crm/brands/'.$brand->id)
            ->assertOk()
            ->assertSeeLivewire(BrandDetail::class)
            ->assertSee('Serum Eins')
            ->assertSee('Kampagne Eins')
            ->assertSee('Run Eins')
            ->assertSee(route('crm.campaigns.show', $campaign))
            ->assertSee(route('crm.seeding.show', $run));
    }

    public function test_component_refuses_client_viewers(): void
    {
        $this->seedRoles();
        $brand = Brand::factory()->create();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        Livewire::test(BrandDetail::class, ['brand' => $brand])->assertForbidden();
    }

    public function test_lists_are_scoped_to_this_brand(): void
    {
        $this->actingAsCrmStaff();
        $brand = Brand::factory()->create();
        $other = Brand::factory()->create();
        Product::factory()->create(['brand_id' => $other->id, 'name' => 'Fremdes Produkt']);

        Livewire::test(BrandDetail::class, ['brand' => $brand])
            ->assertDontSee('Fremdes Produkt');
    }
}
