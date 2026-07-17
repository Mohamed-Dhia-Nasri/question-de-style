<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\CRM\Livewire\Brands\BrandsIndex;
use App\Modules\CRM\Livewire\Campaigns\CampaignsIndex;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Client;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InlineCreateTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_brand_form_creates_a_client_inline_and_selects_it(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(BrandsIndex::class)
            ->call('create')
            ->call('openInlineCreate', 'client')
            ->set('inline_client_name', 'Maison Brückner')
            ->call('saveInlineCreate')
            ->assertHasNoErrors()
            ->assertSet('inlineCreate', null)
            ->assertSet('brand_client_id', (string) Client::query()->where('name', 'Maison Brückner')->firstOrFail()->id)
            ->assertDispatched('notify', type: 'success');
    }

    public function test_campaign_form_creates_a_brand_with_a_new_client_in_one_go(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(CampaignsIndex::class)
            ->call('create')
            ->call('openInlineCreate', 'brand')
            ->set('inline_new_client', true)
            ->set('inline_client_name', 'Neue Agentur')
            ->set('inline_brand_name', 'Atelier Nord')
            ->call('saveInlineCreate')
            ->assertHasNoErrors();

        $brand = Brand::query()->where('name', 'Atelier Nord')->firstOrFail();
        $this->assertSame('Neue Agentur', $brand->client->name);
    }

    public function test_escape_on_the_inline_form_closes_only_the_inline_form(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(BrandsIndex::class)
            ->call('create')
            ->call('openInlineCreate', 'client')
            ->call('cancelForm') // what both Escape handlers reach first
            ->assertSet('inlineCreate', null)
            ->assertSet('showForm', true);
    }

    public function test_inline_create_requires_create_permission(): void
    {
        $this->seedRoles();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(PermissionsCatalog::CRM_VIEW);
        $this->actingAs($viewer);

        Livewire::test(BrandsIndex::class)
            ->call('openInlineCreate', 'client')
            ->assertForbidden();
    }

    public function test_unlisted_type_is_rejected(): void
    {
        $this->actingAsCrmStaff();

        Livewire::test(BrandsIndex::class)
            ->call('openInlineCreate', 'campaign')
            ->assertSet('inlineCreate', null);
    }
}
