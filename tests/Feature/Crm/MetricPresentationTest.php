<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Livewire\Products\ProductsIndex;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Product;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SectorLabel;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MetricPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_index_shows_plain_words_not_enum_constants(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));

        Product::factory()->create([
            'brand_id' => Brand::factory()->create()->id,
            'category' => SectorLabel::FoodBeverage,
            'unit_value' => new MetricValue(49.0, MetricTier::Confirmed),
        ]);

        Livewire::test(ProductsIndex::class)
            ->assertSee('Food & beverage')
            ->assertDontSee('FOOD_BEVERAGE')
            ->assertSee('Entered by you')
            ->assertDontSee('CONFIRMED');
    }
}
