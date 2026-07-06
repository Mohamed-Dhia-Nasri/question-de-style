<?php

namespace App\Platform\Snapshots\Console;

use App\Platform\Snapshots\Contracts\SnapshotScheduler;
use Illuminate\Console\Command;

/**
 * Scheduler entry point for SVC-SnapshotScheduler (ADR-0003). Gated on
 * qds.snapshots.enabled so nothing runs before the connectors and the
 * ENT-MetricSnapshot migration exist (remaining P0 work).
 */
class CaptureSnapshotsCommand extends Command
{
    protected $signature = 'qds:capture-snapshots';

    protected $description = 'Capture recurring MetricSnapshot records for tracked accounts and content (SVC-SnapshotScheduler)';

    public function handle(SnapshotScheduler $scheduler): int
    {
        if (! config('qds.snapshots.enabled')) {
            $this->warn('Snapshot capture is disabled (QDS_SNAPSHOTS_ENABLED=false) — skipping.');

            return self::SUCCESS;
        }

        $count = $scheduler->captureDueSnapshots();

        $this->info("Captured {$count} snapshots.");

        return self::SUCCESS;
    }
}
