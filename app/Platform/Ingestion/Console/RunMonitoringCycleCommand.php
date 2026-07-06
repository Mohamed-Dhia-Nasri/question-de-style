<?php

namespace App\Platform\Ingestion\Console;

use App\Platform\Ingestion\Contracts\IngestionService;
use Illuminate\Console\Command;

/**
 * Scheduler entry point for the recurring roster monitoring cycle
 * (REQ-M1-001, AC-M1-001). Self-gating on qds.ingestion.enabled so the
 * schedule can be registered unconditionally. The cadence is configurable
 * because NO canonical document fixes it (flagged missing decision;
 * governance lands with P4 cost work).
 */
class RunMonitoringCycleCommand extends Command
{
    protected $signature = 'qds:run-monitoring-cycle {--stories-only : Poll only stories (tighter cadence — stories expire)}';

    protected $description = 'Start one monitoring cycle over the active creator roster (SVC-Ingestion)';

    public function handle(IngestionService $ingestion): int
    {
        if (! config('qds.ingestion.enabled')) {
            $this->warn('Ingestion is disabled (QDS_INGESTION_ENABLED=false) — skipping.');

            return self::SUCCESS;
        }

        $storiesOnly = (bool) $this->option('stories-only');

        $ingestion->startMonitoringCycle($storiesOnly);

        $this->info($storiesOnly ? 'Story-only monitoring cycle dispatched.' : 'Monitoring cycle dispatched.');

        return self::SUCCESS;
    }
}
