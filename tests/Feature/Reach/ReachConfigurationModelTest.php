<?php

namespace Tests\Feature\Reach;

use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Platform\Enrichment\Support\ReachConfigurationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReachConfigurationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_casts_params_and_status(): void
    {
        $c = ReachConfiguration::factory()->create(['params' => ['view_weight' => 0.7, 'follower_weight' => 0.1]]);
        $this->assertSame(0.7, $c->params['view_weight']);
        $this->assertInstanceOf(ReachConfigurationStatus::class, $c->status);
        $this->assertFalse($c->isActive());
    }

    public function test_active_state_reports_active(): void
    {
        $c = ReachConfiguration::factory()->active()->create();
        $this->assertTrue($c->isActive());
    }
}
