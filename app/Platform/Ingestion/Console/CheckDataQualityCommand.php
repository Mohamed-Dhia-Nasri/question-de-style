<?php

namespace App\Platform\Ingestion\Console;

use App\Platform\Ingestion\Jobs\RefreshDataQualityJob;
use Illuminate\Console\Command;

/**
 * Scheduler entry point for the periodic data-quality refresh (P4
 * hardening): metric anomalies, snapshot time-series gaps, and stale
 * enrichment-run reaping.
 */
class CheckDataQualityCommand extends Command
{
    protected $signature = 'qds:check-data-quality';

    protected $description = 'Detect metric anomalies and snapshot gaps; reap stale enrichment runs';

    public function handle(): int
    {
        if (! config('qds.ingestion.data_quality.enabled')) {
            $this->warn('Data-quality monitoring is disabled (QDS_DATA_QUALITY_ENABLED=false) — skipping.');

            return self::SUCCESS;
        }

        RefreshDataQualityJob::dispatch();

        $this->info('Data-quality refresh dispatched.');

        return self::SUCCESS;
    }
}
