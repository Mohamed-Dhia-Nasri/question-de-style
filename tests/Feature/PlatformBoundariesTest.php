<?php

namespace Tests\Feature;

use App\Platform\Analytics\NeonAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The platform service boundaries exist but are gated: scheduled commands
 * skip cleanly while disabled and run their live implementation when enabled
 * before their roadmap phase delivers an implementation.
 *
 * SVC-SnapshotScheduler and SVC-Ingestion are implemented as of P1 —
 * their happy paths are covered in tests/Feature/Ingestion and
 * tests/Feature/Snapshots; here we keep only the disabled-gate checks.
 */
class PlatformBoundariesTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_capture_skips_while_disabled(): void
    {
        config(['qds.snapshots.enabled' => false]);

        $this->artisan('qds:capture-snapshots')->assertExitCode(0);
    }

    public function test_snapshot_capture_runs_with_empty_roster_when_enabled(): void
    {
        config(['qds.snapshots.enabled' => true]);

        $this->artisan('qds:capture-snapshots')
            ->expectsOutputToContain('Captured 0 snapshots.')
            ->assertExitCode(0);
    }

    public function test_monitoring_cycle_skips_while_disabled(): void
    {
        config(['qds.ingestion.enabled' => false]);

        $this->artisan('qds:run-monitoring-cycle')->assertExitCode(0);
    }

    public function test_rollup_refresh_skips_while_disabled(): void
    {
        config(['qds.analytics.rollup_refresh_enabled' => false]);

        $this->artisan('qds:refresh-rollups')->assertExitCode(0);
    }

    public function test_rollup_refresh_runs_the_live_analytics_service_when_enabled(): void
    {
        // SVC-Analytics is live (P0 analytics foundation + P1 loaders):
        // the refresh loads facts and refreshes every ROLLUP-* matview.
        config(['qds.analytics.rollup_refresh_enabled' => true]);

        $this->artisan('qds:refresh-rollups')
            ->expectsOutputToContain('Refreshed '.count(NeonAnalyticsService::ROLLUPS).' rollups.')
            ->assertExitCode(0);
    }
}
