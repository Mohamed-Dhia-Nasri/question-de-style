<?php

namespace App\Platform\Export\Console;

use App\Platform\Export\ExportManager;
use Illuminate\Console\Command;

/**
 * Retention enforcement for export artifacts (DP-005): expired files are
 * deleted from private storage and their ledger rows closed. Scheduled
 * alongside the other retention commands.
 */
class PruneExpiredExportsCommand extends Command
{
    protected $signature = 'qds:prune-expired-exports';

    protected $description = 'Delete expired export artifacts from private storage (SVC-Export retention)';

    public function handle(ExportManager $exports): int
    {
        $pruned = $exports->pruneExpired();

        $this->info("Pruned {$pruned} expired exports.");

        return self::SUCCESS;
    }
}
