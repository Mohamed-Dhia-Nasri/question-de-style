<?php

namespace App\Platform\Ingestion\Jobs;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Ingestion\Models\IngestionCycle;
use App\Platform\Ingestion\Support\CycleStatus;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Starts one monitoring cycle over the roster (AC-M1-001, ADR-0011):
 * resolves every active CREATOR MonitoredSubject to its tracked creator's
 * PlatformAccounts (filtered to the subject's platforms) and fans out one
 * PollMonitoredAccountJob per distinct account.
 *
 * Duplicate-cycle prevention (requirement 15/16) is two-layer:
 * ShouldBeUnique keeps a second copy of this job off the queue, and a
 * fresh RUNNING IngestionCycle row makes a concurrent start a no-op.
 *
 * Open-web term subjects (BRAND/KEYWORD/HASHTAG/HANDLE) are DEFERRED
 * (DEF-006) and never processed — roster creators only.
 */
class RunMonitoringCycleJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        /** Story-only cycles run on a tighter cadence (expiry pressure). */
        public readonly bool $storiesOnly = false,
    ) {
        $this->onQueue('ingestion');
    }

    public function uniqueId(): string
    {
        return 'qds-monitoring-cycle:'.($this->storiesOnly ? 'stories' : 'full');
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
            ->where('stories_only', $this->storiesOnly)
            ->where('started_at', '>', $staleAfter)
            ->exists();

        if ($alreadyRunning) {
            Log::info('qds.ingestion: cycle skipped — a fresh cycle is still running (duplicate prevention).');

            return;
        }

        $correlationId = (string) Str::uuid();

        $accounts = $this->rosterAccounts();

        $jobsExpected = 0;

        foreach ($accounts as $account) {
            $jobsExpected += $this->jobCountFor($account);
        }

        $cycle = IngestionCycle::query()->create([
            'correlation_id' => $correlationId,
            'status' => CycleStatus::Running,
            'stories_only' => $this->storiesOnly,
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
            if ($this->jobCountFor($account) > 0) {
                PollMonitoredAccountJob::dispatch($account->id, $cycle->id, $correlationId, $this->storiesOnly);
            }
        }
    }

    /**
     * Distinct platform accounts across the active CREATOR roster,
     * restricted to each subject's monitored platforms.
     *
     * @return list<PlatformAccount>
     */
    private function rosterAccounts(): array
    {
        $subjects = MonitoredSubject::query()
            ->where('active', true)
            ->where('subject_type', MonitoredSubjectType::Creator->value)
            ->whereNotNull('creator_id')
            ->with('creator.platformAccounts')
            ->get();

        $accounts = [];

        foreach ($subjects as $subject) {
            $platforms = collect($subject->platforms ?? [])
                ->map(fn (Platform $p): string => $p->value)
                ->all();

            foreach ($subject->creator->platformAccounts ?? [] as $account) {
                if ($platforms !== [] && ! in_array($account->platform->value, $platforms, true)) {
                    continue;
                }

                $accounts[$account->id] = $account;
            }
        }

        return array_values($accounts);
    }

    private function jobCountFor(PlatformAccount $account): int
    {
        $hasStories = $account->platform === Platform::Instagram;

        if ($this->storiesOnly) {
            return $hasStories ? 1 : 0;
        }

        // profile + content (+ stories on Instagram).
        return 2 + ($hasStories ? 1 : 0);
    }
}
