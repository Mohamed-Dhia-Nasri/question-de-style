<?php

namespace App\Platform\Ingestion\Jobs;

use App\Models\Tenant;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Enrichment\Models\EnrichmentRun;
use App\Platform\Enrichment\Support\EnrichmentRunStatus;
use App\Platform\Ingestion\Observability\AlertService;
use App\Platform\Ingestion\Support\AlertType;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Periodic data-quality refresh (P4 hardening). Provider health tracks
 * whether CALLS fail; this job watches whether the DATA looks wrong even
 * when calls succeed — the signature of silent scraper breakage, which is
 * how the TikTok source degrades (anti-bot changes reshape the actor's
 * output rather than erroring):
 *
 * - metric anomalies: follower counts collapsing to zero or dropping an
 *   implausible share between consecutive snapshots;
 * - snapshot gaps: monitored accounts whose append-only time series has
 *   stopped receiving points;
 * - stale enrichment runs: hard-killed runs left in RUNNING are reaped as
 *   FAILED so the sweep can pick their targets up again (roadmap P4 item,
 *   mirroring the ingestion stale-cycle refresh).
 *
 * Alerts are fingerprint-deduplicated per (type, platform) by AlertService
 * and auto-resolve when a later scan comes back clean.
 */
class RefreshDataQualityJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('ingestion');
    }

    public function handle(AlertService $alerts, TenantContext $context): void
    {
        // Data-quality alert messages embed tenant creator handles, so each
        // scan runs under exactly ONE tenant's bound context (queries scope,
        // alerts carry that tenant). Scheduled multi-tenant processing:
        // initialize context → process one tenant → restore (runAs). One
        // tenant's scan failing must never skip or corrupt the others.
        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($alerts, $context): void {
            $tenantId = (int) $tenant->id;

            try {
                $context->runAs($tenant, function () use ($alerts, $tenantId): void {
                    // Only accounts on the ACTIVE roster are snapshotted
                    // (DatabaseSnapshotScheduler); a de-rostered account stops
                    // getting points by design, so it must not be flagged as
                    // anomalous/gapped — otherwise its alert never clears and
                    // permanently masks real gaps for that platform.
                    $rosterIds = $this->rosterAccountIds();
                    $this->detectMetricAnomalies($alerts, $tenantId, $rosterIds);
                    $this->detectSnapshotGaps($alerts, $tenantId, $rosterIds);
                });
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // Reaping stale enrichment runs is a platform maintenance sweep (a
        // status flip that discloses nothing cross-tenant) — run once,
        // tenant-less, so an over-age RUNNING row in ANY tenant is reaped.
        $this->reapStaleEnrichmentRuns();
    }

    /**
     * Compare each monitored account's two most recent follower snapshots.
     * A zero-collapse or a drop past the configured ratio is anomalous —
     * real audiences shrink slowly; scrapers break abruptly.
     */
    /** @param list<int> $rosterIds */
    private function detectMetricAnomalies(AlertService $alerts, int $tenantId, array $rosterIds): void
    {
        $minFollowers = (int) config('qds.ingestion.data_quality.zero_drop_min_followers');
        $dropRatio = (float) config('qds.ingestion.data_quality.drop_alert_ratio');

        /** @var array<string, list<string>> $anomalies */
        $anomalies = [];
        /** @var array<string, bool> $zeroDrop */
        $zeroDrop = [];

        PlatformAccount::query()
            ->whereIn('id', $rosterIds)
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use ($minFollowers, $dropRatio, &$anomalies, &$zeroDrop): void {
                foreach ($accounts as $account) {
                    $latestTwo = MetricSnapshot::query()
                        ->where('platform_account_id', $account->id)
                        ->orderByDesc('captured_at')
                        ->limit(2)
                        ->get();

                    if ($latestTwo->count() < 2) {
                        continue; // no series yet — nothing to compare
                    }

                    $current = $this->followerAmount($latestTwo[0]);
                    $previous = $this->followerAmount($latestTwo[1]);

                    if ($current === null || $previous === null || $previous < $minFollowers) {
                        continue;
                    }

                    $platform = $account->platform->value;

                    if ($current <= 0.0) {
                        $anomalies[$platform][] = sprintf('@%s %s→0', $account->handle, number_format($previous));
                        $zeroDrop[$platform] = true;
                    } elseif (($previous - $current) / $previous >= $dropRatio) {
                        $anomalies[$platform][] = sprintf(
                            '@%s %s→%s',
                            $account->handle,
                            number_format($previous),
                            number_format($current),
                        );
                    }
                }
            });

        foreach (Platform::cases() as $platform) {
            $found = $anomalies[$platform->value] ?? [];

            if ($found === []) {
                $alerts->resolve(AlertType::MetricAnomaly, $platform->value, $tenantId);

                continue;
            }

            $alerts->raise(
                AlertType::MetricAnomaly,
                $platform->value,
                sprintf(
                    '%d %s account(s) show anomalous follower drops between consecutive snapshots: %s%s — data may be silently broken at the source.',
                    count($found),
                    $platform->value,
                    implode('; ', array_slice($found, 0, 5)),
                    count($found) > 5 ? sprintf(' (+%d more)', count($found) - 5) : '',
                ),
                ($zeroDrop[$platform->value] ?? false) ? 'critical' : 'warning',
                $tenantId,
            );
        }
    }

    /**
     * An account WITH snapshot history but no new point past the gap window
     * has a hole in its growth series (AC-M1-021 relies on continuity).
     */
    /** @param list<int> $rosterIds */
    private function detectSnapshotGaps(AlertService $alerts, int $tenantId, array $rosterIds): void
    {
        $gapBefore = CarbonImmutable::now()
            ->subHours((int) config('qds.ingestion.data_quality.snapshot_gap_hours'));

        $stalled = MetricSnapshot::query()
            ->whereIn('platform_account_id', $rosterIds)
            ->groupBy('platform_account_id')
            ->selectRaw('platform_account_id, MAX(captured_at) AS last_captured_at')
            ->havingRaw('MAX(captured_at) < ?', [$gapBefore])
            ->pluck('last_captured_at', 'platform_account_id');

        /** @var array<string, list<string>> $gaps */
        $gaps = [];

        if ($stalled->isNotEmpty()) {
            $accounts = PlatformAccount::query()->whereIn('id', $stalled->keys())->get();

            foreach ($accounts as $account) {
                $gaps[$account->platform->value][] = sprintf(
                    '@%s (last %s)',
                    $account->handle,
                    CarbonImmutable::parse((string) $stalled[$account->id])->toIso8601String(),
                );
            }
        }

        foreach (Platform::cases() as $platform) {
            $found = $gaps[$platform->value] ?? [];

            if ($found === []) {
                $alerts->resolve(AlertType::SnapshotGap, $platform->value, $tenantId);

                continue;
            }

            $alerts->raise(
                AlertType::SnapshotGap,
                $platform->value,
                sprintf(
                    '%d %s account(s) have a gap in their metric time series: %s%s.',
                    count($found),
                    $platform->value,
                    implode('; ', array_slice($found, 0, 5)),
                    count($found) > 5 ? sprintf(' (+%d more)', count($found) - 5) : '',
                ),
                tenantId: $tenantId,
            );
        }
    }

    /**
     * A hard-killed enrichment job leaves its telemetry row in RUNNING,
     * which the sweep treats as already-handled — the target would never
     * be retried. Reap over-age RUNNING rows as FAILED.
     */
    private function reapStaleEnrichmentRuns(): void
    {
        $staleMinutes = (int) config('qds.enrichment.run_stale_after_minutes');

        if ($staleMinutes <= 0) {
            return;
        }

        EnrichmentRun::query()
            ->where('status', EnrichmentRunStatus::Running->value)
            ->where('started_at', '<', CarbonImmutable::now()->subMinutes($staleMinutes))
            ->update([
                'status' => EnrichmentRunStatus::Failed->value,
                'finished_at' => CarbonImmutable::now(),
                'error' => sprintf(
                    'Reaped by the data-quality monitor: still RUNNING after %d minutes (worker died mid-run).',
                    $staleMinutes,
                ),
            ]);
    }

    /**
     * Account ids on the active CREATOR roster, restricted to each subject's
     * monitored platforms — the same set DatabaseSnapshotScheduler captures
     * for. Runs inside the bound tenant context, so it is tenant-scoped.
     *
     * @return list<int>
     */
    private function rosterAccountIds(): array
    {
        $subjects = MonitoredSubject::query()
            ->where('active', true)
            ->where('subject_type', MonitoredSubjectType::Creator->value)
            ->whereNotNull('creator_id')
            ->with('creator.platformAccounts')
            ->get();

        $ids = [];

        foreach ($subjects as $subject) {
            $platforms = collect($subject->platforms ?? [])
                ->map(fn (Platform $p): string => $p->value)
                ->all();

            foreach ($subject->creator->platformAccounts ?? [] as $account) {
                if ($platforms !== [] && ! in_array($account->platform->value, $platforms, true)) {
                    continue;
                }

                $ids[$account->id] = $account->id;
            }
        }

        return array_values($ids);
    }

    private function followerAmount(MetricSnapshot $snapshot): ?float
    {
        $metrics = $snapshot->metrics ?? [];

        foreach ($metrics as $metric) {
            if ($metric->metric === 'followers') {
                return $metric->amount;
            }
        }

        // Account snapshots historically stored a single unlabeled value —
        // the follower count by construction (DatabaseSnapshotScheduler).
        return count($metrics) === 1 ? $metrics[0]->amount : null;
    }
}
