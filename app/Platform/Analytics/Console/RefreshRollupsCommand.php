<?php

namespace App\Platform\Analytics\Console;

use App\Platform\Analytics\Contracts\AnalyticsService;
use Illuminate\Console\Command;

/**
 * Scheduler entry point for SVC-Analytics rollup refresh (ADR-0010/ADR-0013:
 * Neon has no pg_cron, so the app scheduler drives the refresh). Gated on
 * qds.analytics.rollup_refresh_enabled until the FACT- and ROLLUP- structures exist.
 */
class RefreshRollupsCommand extends Command
{
    protected $signature = 'qds:refresh-rollups';

    protected $description = 'Refresh analytics rollups (ROLLUP-*) from append-only facts (SVC-Analytics)';

    public function handle(AnalyticsService $analytics): int
    {
        if (! config('qds.analytics.rollup_refresh_enabled')) {
            $this->warn('Rollup refresh is disabled (QDS_ANALYTICS_ROLLUP_REFRESH_ENABLED=false) — skipping.');

            return self::SUCCESS;
        }

        $count = $analytics->refreshRollups();

        $this->info("Refreshed {$count} rollups.");

        return self::SUCCESS;
    }
}
