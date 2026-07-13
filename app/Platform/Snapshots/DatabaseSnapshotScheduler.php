<?php

namespace App\Platform\Snapshots;

use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Snapshots\Contracts\SnapshotScheduler;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * SVC-SnapshotScheduler (L3, ADR-0003) — the SOLE producer of historical
 * growth data. Re-captures the CURRENT ingested PUBLIC counts as
 * timestamped, append-only ENT-MetricSnapshot rows; the accumulated series
 * IS the history (AC-M1-008/021). Reads existing accounts/content from the
 * database (architecture §2.1) — it never calls providers and NEVER
 * fabricates backfill: an account with no ingested counts yields no
 * snapshot, and history starts at first collection.
 *
 * Provenance: each snapshot carries the Provenance of the PUBLIC provider
 * that supplied the point-in-time counts (data-source matrix §5).
 *
 * Idempotency: a cache lock prevents concurrent runs, and a minimum
 * per-target spacing prevents duplicate points when the schedule fires
 * again before the interval has passed.
 */
class DatabaseSnapshotScheduler implements SnapshotScheduler
{
    public function captureDueSnapshots(): int
    {
        $lock = Cache::lock('qds:snapshot-run', 600);

        if (! $lock->get()) {
            return 0; // another run is in progress — duplicate prevention
        }

        try {
            return $this->captureAccountSnapshots() + $this->captureContentSnapshots();
        } finally {
            $lock->release();
        }
    }

    private function captureAccountSnapshots(): int
    {
        $captured = 0;

        foreach ($this->rosterAccounts() as $account) {
            // No ingested public counts yet → no snapshot. Never a
            // fabricated zero (DP-001; no backfill per ADR-0003).
            if ($account->follower_count === null) {
                continue;
            }

            if (! $this->isDue(platformAccountId: $account->id)) {
                continue;
            }

            $snapshot = new MetricSnapshot([
                'platform_account_id' => $account->id,
                'content_item_id' => null,
                'captured_at' => CarbonImmutable::now(),
                'metrics' => [$account->follower_count],
                'provenance' => $account->provenance,
            ]);
            // ADR-0019: this scheduler runs tenant-less across all tenants —
            // the snapshot's owner is EXPLICITLY the snapshotted account's
            // tenant, never guessed from ambient context.
            $snapshot->tenant_id = $account->tenant_id;
            $snapshot->save();

            $captured++;
        }

        return $captured;
    }

    private function captureContentSnapshots(): int
    {
        $accountIds = array_map(fn (PlatformAccount $a): int => $a->id, $this->rosterAccounts());

        if ($accountIds === []) {
            return 0;
        }

        $window = CarbonImmutable::now()
            ->subDays((int) config('qds.snapshots.content_window_days'));

        $captured = 0;

        ContentItem::query()
            ->whereIn('platform_account_id', $accountIds)
            ->whereNotNull('public_metrics')
            ->where(fn ($query) => $query
                ->where('published_at', '>=', $window)
                ->orWhereNull('published_at'))
            ->orderBy('id')
            ->chunkById(200, function ($items) use (&$captured): void {
                foreach ($items as $item) {
                    if ($item->public_metrics === [] || $item->public_metrics === null) {
                        continue;
                    }

                    if (! $this->isDue(contentItemId: $item->id)) {
                        continue;
                    }

                    $snapshot = new MetricSnapshot([
                        'platform_account_id' => null,
                        'content_item_id' => $item->id,
                        'captured_at' => CarbonImmutable::now(),
                        'metrics' => $item->public_metrics,
                        'provenance' => $item->provenance,
                    ]);
                    // ADR-0019: explicit ownership from the snapshotted
                    // content item's row (see captureAccountSnapshots()).
                    $snapshot->tenant_id = $item->tenant_id;
                    $snapshot->save();

                    $captured++;
                }
            });

        return $captured;
    }

    /**
     * Minimum spacing between two snapshots of the same target. The cadence
     * itself is NOT canonical (flagged missing decision) — configurable.
     */
    private function isDue(?int $platformAccountId = null, ?int $contentItemId = null): bool
    {
        $minInterval = CarbonImmutable::now()
            ->subMinutes((int) config('qds.snapshots.min_interval_minutes'));

        return ! MetricSnapshot::query()
            ->when($platformAccountId !== null, fn ($q) => $q->where('platform_account_id', $platformAccountId))
            ->when($contentItemId !== null, fn ($q) => $q->where('content_item_id', $contentItemId))
            ->where('captured_at', '>', $minInterval)
            ->exists();
    }

    /**
     * Same roster resolution as the monitoring cycle: active CREATOR
     * subjects → tracked creator's accounts on the monitored platforms.
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
