<?php

namespace App\Platform\Ingestion\Jobs;

use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Ingestion\Support\PollPlan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Per-account fan-out of one monitoring cycle (REQ-M1-001): dispatches the
 * profile and content ingestion jobs for one roster account, per the
 * PollPlan shape (TikTok profile rides in the content payload — rec 4).
 *
 * Stories are NOT part of scheduled full cycles (cost plan rec 2): they
 * run in the tighter story-only cycle, batch-planned by
 * RunMonitoringCycleJob (rec 3). Only on-demand creator runs set
 * $includeStories — an explicit human request fetches everything.
 *
 * WithoutOverlapping keeps two cycles from polling the same account
 * concurrently. Carries only ids — no payloads.
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
        /** Periodic sweep without the refresh-window date filter (rec 1). */
        public readonly bool $fullDepth = false,
        /** On-demand creator runs only: poll stories inline too. */
        public readonly bool $includeStories = false,
        /**
         * Planning-time profile-interval decision (profile polls run
         * weekly by default, not every cycle) — decided by the cycle
         * planner so it always matches the cycle's jobs_expected.
         */
        public readonly bool $includeProfile = true,
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
        // ADR-0019: this job WRITES nothing — it only fans out per-account
        // child jobs, and each child re-derives its tenant context from the
        // account row it loads (TenantContext::runAs). No context needed here.
        $account = PlatformAccount::query()->find($this->platformAccountId);

        if ($account === null) {
            return;
        }

        if ($this->includeProfile && PollPlan::dispatchesProfileJob($account)) {
            IngestProfileJob::dispatch($account->id, $this->cycleId, $this->correlationId);
        }

        IngestContentJob::dispatch($account->id, $this->cycleId, $this->correlationId, $this->fullDepth);

        if ($this->includeStories && PollPlan::storyCapable($account) && PollPlan::storiesEnabled()) {
            IngestStoriesJob::dispatch($account->id, $this->cycleId, $this->correlationId);
        }
    }
}
