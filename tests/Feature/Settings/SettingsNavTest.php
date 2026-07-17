<?php

namespace Tests\Feature\Settings;

use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsNavTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_staff_see_the_settings_section_with_emv_and_reach_links(): void
    {
        $res = $this->actingAs($this->makeUser(RoleName::Analyst))->get('/dashboard');
        $res->assertOk();
        $res->assertSee(route('settings.emv'));
        $res->assertSee(route('settings.reach'));
        $res->assertSee(route('settings.monitoring'));
    }

    public function test_client_viewer_does_not_see_settings_links(): void
    {
        $res = $this->actingAs($this->makeUser(RoleName::ClientViewer))->get('/reports');
        $res->assertOk();
        $res->assertDontSee(route('settings.reach'));
    }
}
