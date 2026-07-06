<?php

namespace App\Platform\Ingestion\Observability;

use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\Models\ProviderHealthState;
use App\Platform\Ingestion\SourceRegistry;
use App\Platform\Ingestion\Support\CallOutcome;
use App\Platform\Ingestion\Support\ProviderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The provider health view (External API Monitoring): per SRC-* provider —
 * current status, last success/failure, consecutive failures, success
 * rate, average and p95 duration, invalid-response rate, stale-data
 * warning, and the most recent sanitized errors, computed over a rolling
 * window of provider_calls.
 */
class ProviderHealthService
{
    /**
     * @return array<string, array<string, mixed>> keyed by SRC-* id
     */
    public function overview(): array
    {
        $windowHours = max(1, (int) config('qds.ingestion.observability.health_window_hours'));
        $staleHours = max(1, (int) config('qds.ingestion.observability.stale_after_hours'));
        $since = CarbonImmutable::now()->subHours($windowHours);

        $states = ProviderHealthState::query()->get()->keyBy('source');

        /** @var Collection<string, object> $stats */
        $stats = DB::table('provider_calls')
            ->where('started_at', '>=', $since)
            ->groupBy('source')
            ->select([
                'source',
                DB::raw('count(*) as total_calls'),
                DB::raw("count(*) filter (where outcome = 'SUCCESS') as success_calls"),
                DB::raw("count(*) filter (where outcome = 'FAILURE') as failure_calls"),
                DB::raw('avg(duration_ms) as avg_duration_ms'),
                DB::raw('percentile_cont(0.95) within group (order by duration_ms) as p95_duration_ms'),
                DB::raw('coalesce(sum(result_count), 0) as result_count'),
                DB::raw('coalesce(sum(rejected_count), 0) as rejected_count'),
            ])
            ->get()
            ->keyBy('source');

        $overview = [];

        foreach (SourceRegistry::all() as $source) {
            $state = $states->get($source);
            $stat = $stats->get($source);

            $totalCalls = (int) ($stat->total_calls ?? 0);
            $resultCount = (int) ($stat->result_count ?? 0);

            $lastSuccessAt = $state?->last_success_at;

            $overview[$source] = [
                'status' => ($state->status ?? ProviderStatus::Unknown)->value,
                'last_success_at' => $lastSuccessAt?->toIso8601String(),
                'last_failure_at' => $state?->last_failure_at?->toIso8601String(),
                'consecutive_failures' => (int) ($state->consecutive_failures ?? 0),
                'window_hours' => $windowHours,
                'total_calls' => $totalCalls,
                'success_rate' => $totalCalls > 0
                    ? round((int) ($stat->success_calls ?? 0) / $totalCalls, 4)
                    : null,
                'avg_duration_ms' => $stat?->avg_duration_ms !== null ? round((float) $stat->avg_duration_ms, 1) : null,
                'p95_duration_ms' => $stat?->p95_duration_ms !== null ? round((float) $stat->p95_duration_ms, 1) : null,
                'invalid_response_rate' => $resultCount > 0
                    ? round((int) ($stat->rejected_count ?? 0) / $resultCount, 4)
                    : null,
                // Stale only applies to providers that have ever been used.
                'stale_data_warning' => $lastSuccessAt !== null
                    && $lastSuccessAt->lt(CarbonImmutable::now()->subHours($staleHours)),
                'recent_errors' => $this->recentErrors($source),
            ];
        }

        return $overview;
    }

    /** @return list<array{at: string, category: string|null, message: string|null}> */
    private function recentErrors(string $source): array
    {
        return ProviderCall::query()
            ->where('source', $source)
            ->where('outcome', CallOutcome::Failure->value)
            ->latest('started_at')
            ->limit(5)
            ->get(['started_at', 'error_category', 'error_message'])
            ->map(fn (ProviderCall $call): array => [
                'at' => $call->started_at->toIso8601String(),
                'category' => $call->error_category?->value,
                'message' => $call->error_message,
            ])
            ->values()
            ->all();
    }
}
