<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Seeding\SeedingCampaignsIndex;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\SeedingType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SeedingFormBehaviourTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_switching_brand_resets_product_and_parent_campaign(): void
    {
        $this->actingAsCrmStaff();
        $brandA = Brand::factory()->create();
        $brandB = Brand::factory()->create();
        $productA = Product::factory()->create(['brand_id' => $brandA->id]);

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->set('seeding_brand_id', (string) $brandA->id)
            ->set('seeding_product_id', (string) $productA->id)
            ->set('seeding_brand_id', (string) $brandB->id)
            ->assertSet('seeding_product_id', '')
            ->assertSet('seeding_campaign_id', '');
    }

    public function test_product_options_are_filtered_to_the_chosen_brand(): void
    {
        $this->actingAsCrmStaff();
        $brandA = Brand::factory()->create();
        $brandB = Brand::factory()->create();
        $productA = Product::factory()->create(['brand_id' => $brandA->id, 'name' => 'Alpha Serum']);
        Product::factory()->create(['brand_id' => $brandB->id, 'name' => 'Beta Balm']);

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->set('seeding_brand_id', (string) $brandA->id)
            ->assertSee('Alpha Serum')
            ->assertDontSee('Beta Balm');
    }

    public function test_create_saves_as_draft_and_ignores_tampered_status_and_spend(): void
    {
        $this->actingAsCrmStaff();
        $brand = Brand::factory()->create();

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->set('seeding_name', 'Autumn Gifting')
            ->set('seeding_type', SeedingType::Gifting->value)
            ->set('seeding_brand_id', (string) $brand->id)
            ->set('seeding_status', SeedingCampaignStatus::Active->value)
            ->set('seeding_spend', '500')
            ->call('save')
            ->assertHasNoErrors();

        $run = SeedingCampaign::query()->where('name', 'Autumn Gifting')->firstOrFail();
        $this->assertSame(SeedingCampaignStatus::Draft, $run->status);
        $this->assertNull($run->spend);
    }

    public function test_create_modal_shows_no_status_or_spend_fields(): void
    {
        $this->actingAsCrmStaff();
        Brand::factory()->create();

        Livewire::test(SeedingCampaignsIndex::class)
            ->call('create')
            ->assertDontSeeHtml('id="seeding_status"')
            ->assertDontSeeHtml('id="seeding_spend"');
    }
}
