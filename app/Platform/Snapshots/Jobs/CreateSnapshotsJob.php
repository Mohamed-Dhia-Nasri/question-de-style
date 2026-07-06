<?php

namespace App\Platform\Snapshots\Jobs;

use App\Platform\Snapshots\Contracts\SnapshotScheduler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Queued entry point for SVC-SnapshotScheduler (ADR-0003). Unique on the
 * queue AND lock-guarded inside the scheduler, so overlapping schedule
 * fires can never produce duplicate snapshot points. Safe to replay: the
 * per-target minimum spacing makes a re-run a no-op.
 */
class CreateSnapshotsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('snapshots');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [300];
    }

    public function handle(SnapshotScheduler $scheduler): void
    {
        $count = $scheduler->captureDueSnapshots();

        Log::info("qds.snapshots: captured {$count} metric snapshots.");
    }
}
