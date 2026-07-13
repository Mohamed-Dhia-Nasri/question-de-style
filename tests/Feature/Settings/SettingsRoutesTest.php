<?php

namespace Tests\Feature\Settings;

use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_staff_can_open_settings_emv_and_reach(): void
    {
        $analyst = $this->makeUser(RoleName::Analyst);
        $this->actingAs($analyst)->get('/settings/emv')->assertOk();
        $this->actingAs($analyst)->get('/settings/reach')->assertOk();
    }

    public function test_client_viewer_is_forbidden_from_settings(): void
    {
        $client = $this->makeUser(RoleName::ClientViewer);
        $this->actingAs($client)->get('/settings/emv')->assertForbidden();
        $this->actingAs($client)->get('/settings/reach')->assertForbidden();
    }

    public function test_old_monitoring_emv_route_is_gone(): void
    {
        $analyst = $this->makeUser(RoleName::Analyst);
        $this->actingAs($analyst)->get('/monitoring/emv')->assertNotFound();
    }
}
