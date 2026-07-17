<?php

namespace Tests\Feature\Crm;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Stage B (F15/F16): every detail page shows its place in the hierarchy as links. */
class ContextHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCrmStaff(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));
    }

    public function test_campaign_detail_shows_client_and_brand_as_links(): void
    {
        $this->actingAsCrmStaff();
        $client = Client::factory()->create(['name' => 'Brückner GmbH']);
        $brand = Brand::factory()->create(['client_id' => $client->id, 'name' => 'Atelier Nord']);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id]);

        $this->get('/crm/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertSee('Brückner GmbH')
            ->assertSee(route('crm.brands.show', $brand));
    }

    public function test_seeding_detail_shows_the_full_chain_including_parent_campaign(): void
    {
        $this->actingAsCrmStaff();
        $client = Client::factory()->create(['name' => 'Brückner GmbH']);
        $brand = Brand::factory()->create(['client_id' => $client->id]);
        $campaign = Campaign::factory()->create(['brand_id' => $brand->id, 'name' => 'Creator Week']);
        $seeding = SeedingCampaign::factory()->create(['brand_id' => $brand->id, 'campaign_id' => $campaign->id]);

        $this->get('/crm/seeding/'.$seeding->id)
            ->assertOk()
            ->assertSee('Brückner GmbH')
            ->assertSee(route('crm.brands.show', $brand))
            ->assertSee(route('crm.campaigns.show', $campaign));
    }

    public function test_brand_detail_route_renders_for_staff_and_404s_foreign_tenants(): void
    {
        $this->actingAsCrmStaff();
        $brand = Brand::factory()->create(['name' => 'Atelier Nord']);

        $this->get('/crm/brands/'.$brand->id)->assertOk()->assertSee('Atelier Nord');

        $tenantB = $this->makeTenant('Tenant B');
        $foreign = $this->withTenant($tenantB, fn () => Brand::factory()->create());
        $this->get('/crm/brands/'.$foreign->id)->assertNotFound();
    }

    public function test_brand_detail_is_refused_for_client_viewers(): void
    {
        $this->seedRoles();
        $brand = Brand::factory()->create();
        $this->actingAs($this->makeUser(RoleName::ClientViewer));

        $this->get('/crm/brands/'.$brand->id)->assertForbidden();
    }
}
