<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Clients\ClientsIndex;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Client;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Clients master data (REQ-M3-005) — UsersIndex-pattern coverage: render,
 * search, validation, audit, restrict-FK delete protection, and the
 * crm.view/crm.manage split incl. direct-mutator bypass.
 */
class ClientsCrudTest extends TestCase
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
        $this->get('/crm/clients')->assertOk()->assertSeeLivewire(ClientsIndex::class);

        $this->actingAs($this->makeUser(RoleName::ClientViewer));
        $this->get('/crm/clients')->assertForbidden();
        Livewire::test(ClientsIndex::class)->assertForbidden();
    }

    public function test_search_filters_by_name(): void
    {
        $this->actingAsCrmStaff();

        Client::factory()->create(['name' => 'Agentur Nord']);
        Client::factory()->create(['name' => 'Studio Süd']);

        Livewire::test(ClientsIndex::class)
            ->set('search', 'nord')
            ->assertSee('Agentur Nord')
            ->assertDontSee('Studio Süd');
    }

    public function test_create_validates_and_persists_with_an_audit_event(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(ClientsIndex::class)
            ->call('create')
            ->set('client_name', '')
            ->set('client_country', 'DEU')
            ->call('save')
            // Country validates against the closed operating set (Country
            // enum) since the operator-geography change — 'DEU' is not ISO-2.
            ->assertHasErrors(['client_name' => 'required', 'client_country' => 'in']);

        Livewire::test(ClientsIndex::class)
            ->call('create')
            ->set('client_name', 'Neue Agentur')
            ->set('client_country', 'de')
            ->call('save')
            ->assertHasNoErrors();

        $client = Client::where('name', 'Neue Agentur')->firstOrFail();
        $this->assertSame('DE', $client->country);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.created', 'subject_id' => $client->id]);
    }

    public function test_edit_updates_the_client(): void
    {
        $this->actingAsCrmStaff();

        $client = Client::factory()->create(['name' => 'Old Name']);

        Livewire::test(ClientsIndex::class)
            ->call('edit', $client->id)
            ->assertSet('client_name', 'Old Name')
            ->set('client_name', 'New Name')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => 'New Name']);
    }

    public function test_delete_is_refused_while_brands_exist_then_succeeds(): void
    {
        $this->actingAsCrmStaff();

        $client = Client::factory()->create();
        $brand = Brand::factory()->create(['client_id' => $client->id]);

        Livewire::test(ClientsIndex::class)
            ->call('confirmDelete', $client->id)
            ->call('delete');

        $this->assertDatabaseHas('clients', ['id' => $client->id]);

        $brand->delete();

        Livewire::test(ClientsIndex::class)
            ->call('confirmDelete', $client->id)
            ->call('delete');

        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.deleted', 'subject_id' => $client->id]);
    }

    public function test_client_rows_list_their_brands_with_links_to_brand_detail(): void
    {
        $this->actingAsCrmStaff();

        $client = Client::factory()->create(['name' => 'Agentur Nord']);
        $brandA = Brand::factory()->create(['client_id' => $client->id, 'name' => 'Brand Aurora']);
        $brandB = Brand::factory()->create(['client_id' => $client->id, 'name' => 'Brand Borealis']);

        Livewire::test(ClientsIndex::class)
            ->assertSee('Brand Aurora')
            ->assertSee('Brand Borealis')
            ->assertSee(route('crm.brands.show', $brandA), false)
            ->assertSee(route('crm.brands.show', $brandB), false);
    }

    public function test_brandless_client_shows_an_inline_no_brands_line(): void
    {
        $this->actingAsCrmStaff();

        Client::factory()->create(['name' => 'Agentur Nord']);

        Livewire::test(ClientsIndex::class)
            ->assertSee('No brands yet');
    }

    public function test_the_page_title_is_clients_and_brands(): void
    {
        $this->actingAsCrmStaff();

        $this->get('/crm/clients')->assertOk()->assertSee('Clients & Brands');
    }

    public function test_mutating_actions_require_crm_manage_not_just_crm_view(): void
    {
        $this->seedRoles();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        $client = Client::factory()->create();

        Livewire::test(ClientsIndex::class)->assertOk()
            ->call('create')->assertForbidden();
        Livewire::test(ClientsIndex::class)
            ->set('client_name', 'Smuggled Client')
            ->call('save')->assertForbidden();
        Livewire::test(ClientsIndex::class)
            ->set('confirmingDeleteId', $client->id)
            ->call('delete')->assertForbidden();

        $this->assertDatabaseMissing('clients', ['name' => 'Smuggled Client']);
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }
}
