<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Products\ProductsIndex;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Products master data (REQ-M3-005/013): the seeding aggregation key.
 * unitValue persists as a MetricValue at tier CONFIRMED (manual agency
 * input, DP-001); restrict-FK delete protection; view/manage split.
 */
class ProductsCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): User
    {
        $this->seedRoles();

        $staff = $this->makeUser(RoleName::InfluencerRelationsManager);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_component_renders_and_client_viewers_are_refused(): void
    {
        $this->actingAsCrmStaff();
        $this->get('/crm/products')->assertOk()->assertSeeLivewire(ProductsIndex::class);

        $this->actingAs($this->makeUser(RoleName::ClientViewer));
        $this->get('/crm/products')->assertForbidden();
        Livewire::test(ProductsIndex::class)->assertForbidden();
    }

    public function test_create_validates_and_persists_the_confirmed_tier_unit_value(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(ProductsIndex::class)
            ->call('create')
            ->set('product_name', '')
            ->set('product_unit_value', '-5')
            ->call('save')
            ->assertHasErrors([
                'product_brand_id' => 'required',
                'product_name' => 'required',
                'product_unit_value' => 'min',
            ]);

        $brand = Brand::factory()->create();

        Livewire::test(ProductsIndex::class)
            ->call('create')
            ->set('product_brand_id', (string) $brand->id)
            ->set('product_name', 'Sérum No. 5')
            ->set('product_sku', 'SRM-005')
            ->set('product_unit_value', '49.90')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Sérum No. 5')->firstOrFail();
        $this->assertSame(49.90, $product->unit_value->amount);
        // Manual agency input → tier CONFIRMED; the tier travels with the number.
        $this->assertSame(MetricTier::Confirmed, $product->unit_value->tier);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.created', 'subject_id' => $product->id]);
    }

    public function test_edit_updates_the_product(): void
    {
        $this->actingAsCrmStaff();

        $product = Product::factory()->create();

        Livewire::test(ProductsIndex::class)
            ->call('edit', $product->id)
            ->assertSet('product_name', $product->name)
            ->set('product_name', 'Renamed Product')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Renamed Product']);
    }

    public function test_delete_is_refused_while_seeding_campaigns_reference_the_product(): void
    {
        $this->actingAsCrmStaff();

        $product = Product::factory()->create();
        SeedingCampaign::factory()->withProduct($product)->create(['brand_id' => $product->brand_id]);

        Livewire::test(ProductsIndex::class)
            ->call('confirmDelete', $product->id)
            ->call('delete');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_mutating_actions_require_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $product = Product::factory()->create();

        Livewire::test(ProductsIndex::class)->assertOk()
            ->call('create')->assertForbidden();
        Livewire::test(ProductsIndex::class)
            ->set('product_name', 'Smuggled Product')
            ->call('save')->assertForbidden();
        Livewire::test(ProductsIndex::class)
            ->set('confirmingDeleteId', $product->id)
            ->call('delete')->assertForbidden();

        $this->assertDatabaseMissing('products', ['name' => 'Smuggled Product']);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }
}
