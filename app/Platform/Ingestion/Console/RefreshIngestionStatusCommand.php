<?php

namespace App\Platform\Ingestion\Console;

use App\Platform\Ingestion\Jobs\RefreshIngestionStatusJob;
use Illuminate\Console\Command;

/**
 * Scheduler entry point for the periodic ingestion-status refresh
 * (stale cycles, stale-data warnings, story-polling risk).
 */
class RefreshIngestionStatusCommand extends Command
{
    protected $signature = 'qds:refresh-ingestion-status';

    protected $description = 'Refresh ingestion cycle/provider status and raise stale-data alerts';

    public function handle(): int
    {
        if (! config('qds.ingestion.enabled')) {
            $this->warn('Ingestion is disabled (QDS_INGESTION_ENABLED=false) — skipping.');

            return self::SUCCESS;
        }

        RefreshIngestionStatusJob::dispatch();

        $this->info('Ingestion status refresh dispatched.');

        return self::SUCCESS;
    }
}
