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
 * CRM UX Stage C follow-up — brand creation is reachable from the Clients &
 * Brands hierarchy page itself (not only the Overview quick action), via the
 * shared WithInlineCreate brand path.
 */
class ClientsBrandCreateTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_toolbar_new_brand_creates_a_brand_under_the_chosen_client(): void
    {
        $this->actingAsCrmStaff();
        $client = Client::factory()->create();

        Livewire::test(ClientsIndex::class)
            ->call('openInlineCreate', 'brand')
            ->assertSet('inlineCreate', 'brand')
            ->set('inline_brand_client_id', (string) $client->id)
            ->set('inline_brand_name', 'Atelier Nord')
            ->call('saveInlineCreate')
            ->assertHasNoErrors()
            ->assertSet('inlineCreate', null)
            ->assertDispatched('notify', type: 'success');

        $brand = Brand::query()->where('name', 'Atelier Nord')->firstOrFail();
        $this->assertSame($client->id, $brand->client_id);
    }

    public function test_add_brand_for_client_preselects_that_client(): void
    {
        $this->actingAsCrmStaff();
        $client = Client::factory()->create();

        Livewire::test(ClientsIndex::class)
            ->call('addBrandForClient', $client->id)
            ->assertSet('inlineCreate', 'brand')
            ->assertSet('inline_brand_client_id', (string) $client->id)
            ->set('inline_brand_name', 'Studio Umlaut')
            ->call('saveInlineCreate')
            ->assertHasNoErrors();

        $this->assertSame($client->id, Brand::query()->where('name', 'Studio Umlaut')->firstOrFail()->client_id);
    }

    public function test_new_brand_button_is_shown_and_the_dead_end_link_is_gone(): void
    {
        $this->actingAsCrmStaff();
        Client::factory()->create();

        Livewire::test(ClientsIndex::class)
            ->assertSee('New brand')
            ->assertSee('Add brand')
            ->assertDontSee('create one on the');
    }

    public function test_inline_brand_create_requires_create_permission(): void
    {
        $this->seedRoles();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        Livewire::test(ClientsIndex::class)
            ->call('openInlineCreate', 'brand')
            ->assertForbidden();
    }

    public function test_escape_on_the_inline_brand_form_leaves_the_client_form_untouched(): void
    {
        $this->actingAsCrmStaff();
        Client::factory()->create();

        Livewire::test(ClientsIndex::class)
            ->call('openInlineCreate', 'brand')
            ->call('cancelForm')
            ->assertSet('inlineCreate', null)
            ->assertSet('showForm', false);
    }
}
