<?php

namespace Tests\Feature\Crm;

use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Stage B (F16/F19): CRM has sub-navigation; stub areas leave the nav. */
class CrmNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_pages_show_the_crm_sub_navigation(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));

        $response = $this->get('/crm/campaigns');
        $response->assertOk()
            ->assertSee('Clients &amp; Brands', false)
            ->assertSee('Seeding runs')
            ->assertSee(route('crm.tasks.index'));
    }

    public function test_non_crm_pages_do_not_show_the_crm_children(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::InfluencerRelationsManager));

        $this->get('/dashboard')->assertOk()->assertDontSee(route('crm.tasks.index'));
    }

    public function test_discovery_and_reports_left_the_sidebar_but_stay_routable(): void
    {
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Admin));

        $this->get('/dashboard')->assertOk()->assertDontSee(route('discovery.index'));
        $this->get('/discovery')->assertOk();
        $this->get('/reports')->assertOk();
    }
}
