<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_health_endpoint_reports_database_status_and_build_metadata(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonStructure(['app' => ['name', 'env', 'build_sha', 'build_time']]);
    }

    public function test_responses_carry_a_request_id_and_secure_headers(): void
    {
        $response = $this->get('/health');

        $response->assertHeader('X-Request-Id');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
