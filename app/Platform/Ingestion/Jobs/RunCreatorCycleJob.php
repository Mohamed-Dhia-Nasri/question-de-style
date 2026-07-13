<?php

namespace App\Platform\Ingestion\Jobs;

use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Ingestion\Models\IngestionCycle;
use App\Platform\Ingestion\Support\CycleStatus;
use App\Platform\Ingestion\Support\PollPlan;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One on-demand monitoring cycle over a SINGLE creator's platform accounts
 * (operator "run monitoring now"). Deliberately ignores the roster's
 * platform filter — an explicit human request polls every account the
 * creator has. Uses the same IngestionCycle bookkeeping and per-account
 * fan-out as the whole-roster cycle, so operations dashboards and job
 * counters see it like any other run; the cycle row carries creator_id so
 * the whole-roster duplicate guard never mistakes it for a global cycle.
 *
 * Duplicate prevention is per creator, two-layer like the global cycle:
 * ShouldBeUnique keeps a second copy off the queue, and a fresh RUNNING
 * cycle row for the same creator makes a concurrent start a no-op.
 */
class RunCreatorCycleJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $creatorId)
    {
        $this->onQueue('ingestion');
    }

    public function uniqueId(): string
    {
        return 'qds-creator-cycle:'.$this->creatorId;
    }

    public function uniqueFor(): int
    {
        return (int) config('qds.ingestion.cycle_stale_after_minutes') * 60;
    }

    public function handle(): void
    {
        $staleAfter = CarbonImmutable::now()
            ->subMinutes((int) config('qds.ingestion.cycle_stale_after_minutes'));

        $alreadyRunning = IngestionCycle::query()
            ->where('status', CycleStatus::Running->value)
            ->where('creator_id', $this->creatorId)
            ->where('started_at', '>', $staleAfter)
            ->exists();

        if ($alreadyRunning) {
            Log::info('qds.ingestion: creator cycle skipped — a fresh run for this creator is still in flight.', [
                'creator_id' => $this->creatorId,
            ]);

            return;
        }

        /** @var list<PlatformAccount> $accounts */
        $accounts = PlatformAccount::query()
            ->where('creator_id', $this->creatorId)
            ->get()
            ->all();

        $jobsExpected = 0;

        foreach ($accounts as $account) {
            // On-demand runs poll everything the creator has, stories
            // included (explicit human request — adaptive demotion and the
            // full-cycle story exclusion do not apply here).
            $jobsExpected += PollPlan::jobCountFor($account, includeStories: true);
        }

        $correlationId = (string) Str::uuid();

        // ADR-0019: the IngestionCycle ledger is a GLOBAL (tenant-less)
        // operational table; the per-account jobs dispatched below re-derive
        // their tenant from the account row they process. No other writes here.
        $cycle = IngestionCycle::query()->create([
            'correlation_id' => $correlationId,
            'status' => CycleStatus::Running,
            'stories_only' => false,
            'creator_id' => $this->creatorId,
            'accounts_count' => count($accounts),
            'jobs_expected' => $jobsExpected,
            'jobs_pending' => $jobsExpected,
            'jobs_failed' => 0,
            'started_at' => CarbonImmutable::now(),
        ]);

        if ($jobsExpected === 0) {
            $cycle->update(['status' => CycleStatus::Completed, 'finished_at' => CarbonImmutable::now()]);

            return;
        }

        foreach ($accounts as $account) {
            PollMonitoredAccountJob::dispatch($account->id, $cycle->id, $correlationId, false, includeStories: true);
        }
    }
}
