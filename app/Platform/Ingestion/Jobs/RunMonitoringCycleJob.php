<?php

namespace App\Platform\Ingestion\Jobs;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Ingestion\Models\IngestionCycle;
use App\Platform\Ingestion\Support\AdaptiveCadence;
use App\Platform\Ingestion\Support\CadenceSettings;
use App\Platform\Ingestion\Support\CycleStatus;
use App\Platform\Ingestion\Support\PollPlan;
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
 * PlatformAccounts (filtered to the subject's platforms) and fans out.
 *
 * Cost plan shape (reviews/PLAN-apify-cost-optimization-2026-07-07.md):
 * - FULL cycles fan out one PollMonitoredAccountJob per due account
 *   (AdaptiveCadence demotes dormant accounts — rec 7) and carry the
 *   full-depth flag when the periodic no-date-filter sweep is due (rec 1).
 * - STORY cycles are gated on qds.ingestion.stories_enabled (rec 2) and
 *   fan out BATCHED IngestStoriesBatchJob chunks — one actor run covers
 *   many handles, amortizing the story actor's per-run start fee (rec 3).
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

    public function handle(AdaptiveCadence $cadence): void
    {
        if ($this->storiesOnly && ! PollPlan::storiesEnabled()) {
            Log::info('qds.ingestion: story cycle skipped — story polling is disabled (QDS_INGESTION_STORIES_ENABLED).');

            return;
        }

        // The story cron only defines the available SLOTS (every 4h by
        // default); the operator-chosen plan decides how many actually run
        // per day. Enforced as a minimum gap so the runs spread across the
        // day instead of clustering at midnight: 1/day → every ~24h,
        // 2/day → every ~12h, 6/day → every slot. The -1h buffer absorbs
        // scheduler drift at the gap boundary.
        if ($this->storiesOnly) {
            $perDay = app(CadenceSettings::class)->storiesPerDay();

            if ($perDay <= 0) {
                Log::info('qds.ingestion: story cycle skipped — the monitoring plan sets 0 story polls per day.');

                return;
            }

            $minGapHours = max(1, intdiv(24, min(24, $perDay)) - 1);

            // Only cycles that actually polled (in-progress or completed)
            // consume the day's slot. A wedged/failed story cycle reaped to
            // STALE (or marked FAILED) collected nothing, so it must NOT
            // suppress the next slot — stories expire in 24h and there is no
            // same-day retry, so a coincident fault would otherwise lose a
            // full day of stories roster-wide.
            $recentStoryCycle = IngestionCycle::query()
                ->where('stories_only', true)
                ->whereNull('creator_id')
                ->whereIn('status', [
                    CycleStatus::Running->value,
                    CycleStatus::Completed->value,
                    CycleStatus::Partial->value,
                ])
                ->where('started_at', '>', CarbonImmutable::now()->subHours($minGapHours))
                ->exists();

            if ($recentStoryCycle) {
                Log::info('qds.ingestion: story cycle skipped — the plan allows '.$perDay.' story poll(s) per day and one ran recently.');

                return;
            }
        }

        $staleAfter = CarbonImmutable::now()
            ->subMinutes((int) config('qds.ingestion.cycle_stale_after_minutes'));

        // creator_id-scoped rows are on-demand single-creator runs
        // (RunCreatorCycleJob) — they never count as a running GLOBAL cycle.
        $alreadyRunning = IngestionCycle::query()
            ->where('status', CycleStatus::Running->value)
            ->where('stories_only', $this->storiesOnly)
            ->whereNull('creator_id')
            ->where('started_at', '>', $staleAfter)
            ->exists();

        if ($alreadyRunning) {
            Log::info('qds.ingestion: cycle skipped — a fresh cycle is still running (duplicate prevention).');

            return;
        }

        if ($this->storiesOnly) {
            $this->startStoryCycle($cadence);

            return;
        }

        $this->startFullCycle($cadence);
    }

    private function startFullCycle(AdaptiveCadence $cadence): void
    {
        $correlationId = (string) Str::uuid();
        $fullDepth = $this->isFullDepthSweepDue();

        $accounts = array_values(array_filter(
            $this->rosterAccounts(),
            fn (PlatformAccount $account): bool => $cadence->shouldPollContent($account),
        ));

        // The profile-interval decision is made ONCE here and passed to the
        // dispatch unchanged — evaluating it again at dispatch time could
        // drift from jobs_expected and wedge the cycle.
        $withProfile = [];
        $jobsExpected = 0;

        foreach ($accounts as $account) {
            $withProfile[$account->id] = $cadence->shouldPollProfile($account);
            $jobsExpected += PollPlan::jobCountFor($account, includeProfile: $withProfile[$account->id]);
        }

        $cycle = $this->createCycle($correlationId, false, $fullDepth, count($accounts), $jobsExpected);

        if ($cycle === null) {
            return;
        }

        foreach ($accounts as $account) {
            PollMonitoredAccountJob::dispatch(
                $account->id,
                $cycle->id,
                $correlationId,
                $fullDepth,
                includeProfile: $withProfile[$account->id],
            );
        }
    }

    private function startStoryCycle(AdaptiveCadence $cadence): void
    {
        $correlationId = (string) Str::uuid();

        $accounts = array_values(array_filter(
            $this->rosterAccounts(),
            fn (PlatformAccount $account): bool => PollPlan::storyCapable($account)
                && $cadence->shouldPollStories($account),
        ));

        $chunks = array_chunk(
            array_map(fn (PlatformAccount $account): int => $account->id, $accounts),
            max(1, (int) config('qds.ingestion.story_batch_size')),
        );

        $cycle = $this->createCycle($correlationId, true, false, count($accounts), count($chunks));

        if ($cycle === null) {
            return;
        }

        foreach ($chunks as $chunk) {
            IngestStoriesBatchJob::dispatch($chunk, $cycle->id, $correlationId);
        }
    }

    private function createCycle(
        string $correlationId,
        bool $storiesOnly,
        bool $fullDepth,
        int $accountsCount,
        int $jobsExpected,
    ): ?IngestionCycle {
        $cycle = IngestionCycle::query()->create([
            'correlation_id' => $correlationId,
            'status' => CycleStatus::Running,
            'stories_only' => $storiesOnly,
            'full_depth' => $fullDepth,
            'accounts_count' => $accountsCount,
            'jobs_expected' => $jobsExpected,
            'jobs_pending' => $jobsExpected,
            'jobs_failed' => 0,
            'started_at' => CarbonImmutable::now(),
        ]);

        if ($jobsExpected === 0) {
            $cycle->update(['status' => CycleStatus::Completed, 'finished_at' => CarbonImmutable::now()]);

            return null;
        }

        return $cycle;
    }

    /**
     * The periodic full-depth sweep (cost plan rec 1): at most once per
     * full_sweep_interval_days, one full cycle runs WITHOUT the refresh-
     * window date filter to catch late-blooming engagement on posts older
     * than the window. Moot when windowing itself is disabled.
     */
    private function isFullDepthSweepDue(): bool
    {
        $intervalDays = (int) config('qds.ingestion.full_sweep_interval_days');

        if ($intervalDays <= 0 || (int) config('qds.ingestion.refresh_window_days') <= 0) {
            return false;
        }

        // A sweep counts as "done" only if a full-depth cycle actually
        // COMPLETED (or partially completed). A full-depth cycle that failed,
        // stalled to STALE, or dispatched zero jobs never ran the deep
        // no-window backfill, so it must not suppress the next sweep for the
        // whole interval — otherwise late-blooming engagement on posts older
        // than the refresh window is silently never captured.
        return ! IngestionCycle::query()
            ->where('full_depth', true)
            ->where('stories_only', false)
            ->whereNull('creator_id')
            ->whereIn('status', [CycleStatus::Completed->value, CycleStatus::Partial->value])
            ->where('started_at', '>', CarbonImmutable::now()->subDays($intervalDays))
            ->exists();
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
}
