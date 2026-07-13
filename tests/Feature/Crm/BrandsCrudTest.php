<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Brands\BrandsIndex;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Models\Product;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SectorLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Brands master data (REQ-M3-005): client-scoped creation, closed
 * ENUM-SectorLabel validation, line-parsed alias lists, restrict-FK delete
 * protection, and the crm.view/crm.manage split.
 */
class BrandsCrudTest extends TestCase
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
        $this->get('/crm/brands')->assertOk()->assertSeeLivewire(BrandsIndex::class);

        $this->actingAs($this->makeUser(RoleName::ClientViewer));
        $this->get('/crm/brands')->assertForbidden();
        Livewire::test(BrandsIndex::class)->assertForbidden();
    }

    public function test_create_validates_client_and_sector_closed_set(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(BrandsIndex::class)
            ->call('create')
            ->set('brand_name', '')
            ->set('brand_sector', 'SPACE_TRAVEL')
            ->call('save')
            ->assertHasErrors([
                'brand_client_id' => 'required',
                'brand_name' => 'required',
                'brand_sector' => 'in',
            ]);
    }

    public function test_create_persists_with_parsed_aliases_and_an_audit_event(): void
    {
        $this->actingAsCrmStaff();

        $client = Client::factory()->create();

        Livewire::test(BrandsIndex::class)
            ->call('create')
            ->set('brand_client_id', (string) $client->id)
            ->set('brand_name', 'Maison Lumière')
            ->set('brand_sector', SectorLabel::Beauty->value)
            ->set('brand_aliases', "maisonlumiere\n @maison.lumiere \n")
            ->call('save')
            ->assertHasNoErrors();

        $brand = Brand::where('name', 'Maison Lumière')->firstOrFail();
        $this->assertSame($client->id, $brand->client_id);
        $this->assertSame(SectorLabel::Beauty, $brand->sector);
        $this->assertSame(['maisonlumiere', '@maison.lumiere'], $brand->aliases);
        $this->assertDatabaseHas('audit_logs', ['action' => 'brand.created', 'subject_id' => $brand->id]);
    }

    public function test_edit_updates_the_brand(): void
    {
        $this->actingAsCrmStaff();

        $brand = Brand::factory()->create();

        Livewire::test(BrandsIndex::class)
            ->call('edit', $brand->id)
            ->assertSet('brand_name', $brand->name)
            ->set('brand_name', 'Renamed Brand')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'name' => 'Renamed Brand']);
    }

    public function test_delete_is_refused_while_products_exist(): void
    {
        $this->actingAsCrmStaff();

        $brand = Brand::factory()->create();
        Product::factory()->create(['brand_id' => $brand->id]);

        Livewire::test(BrandsIndex::class)
            ->call('confirmDelete', $brand->id)
            ->call('delete');

        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'brand.deleted', 'subject_id' => $brand->id]);
    }

    public function test_mutating_actions_require_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $brand = Brand::factory()->create();

        Livewire::test(BrandsIndex::class)->assertOk()
            ->call('create')->assertForbidden();
        Livewire::test(BrandsIndex::class)
            ->set('brand_name', 'Smuggled Brand')
            ->call('save')->assertForbidden();
        Livewire::test(BrandsIndex::class)
            ->set('confirmingDeleteId', $brand->id)
            ->call('delete')->assertForbidden();

        $this->assertDatabaseMissing('brands', ['name' => 'Smuggled Brand']);
        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
    }
}
