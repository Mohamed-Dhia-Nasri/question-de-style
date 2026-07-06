<?php

namespace App\Platform\Ingestion\Jobs;

use App\Modules\CRM\Models\PlatformAccount;
use App\Shared\Enums\Platform;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Per-account fan-out of one monitoring cycle (REQ-M1-001): dispatches the
 * profile, content, and (Instagram) story ingestion jobs for one roster
 * account. WithoutOverlapping keeps two cycles from polling the same
 * account concurrently. Carries only ids — no payloads.
 */
class PollMonitoredAccountJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly int $platformAccountId,
        public readonly int $cycleId,
        public readonly string $correlationId,
        public readonly bool $storiesOnly = false,
    ) {
        $this->onQueue('ingestion');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('qds-account:'.$this->platformAccountId))
                ->releaseAfter(120)
                ->expireAfter(600),
        ];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(): void
    {
        $account = PlatformAccount::query()->find($this->platformAccountId);

        if ($account === null) {
            return;
        }

        $hasStories = $account->platform === Platform::Instagram;

        if ($this->storiesOnly) {
            if ($hasStories) {
                IngestStoriesJob::dispatch($account->id, $this->cycleId, $this->correlationId);
            }

            return;
        }

        IngestProfileJob::dispatch($account->id, $this->cycleId, $this->correlationId);
        IngestContentJob::dispatch($account->id, $this->cycleId, $this->correlationId);

        if ($hasStories) {
            IngestStoriesJob::dispatch($account->id, $this->cycleId, $this->correlationId);
        }
    }
}
