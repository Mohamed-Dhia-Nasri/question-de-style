<?php

namespace App\Platform\Ingestion\Observability;

use App\Platform\Ingestion\Models\ProviderHealthState;
use App\Platform\Ingestion\Support\ProviderStatus;
use Illuminate\Support\Facades\Cache;

/**
 * Cost-side circuit breaker over the provider health state (cost plan
 * recs 2+9): a provider that is FAILING with a PERMANENT error category
 * (auth/access/not-found — e.g. a paywalled rental actor) is skipped
 * instead of re-invoked every cycle; retrying a permanent failure only
 * burns provider budget and telemetry rows.
 *
 * Recovery is automatic: once the cooldown elapses, exactly ONE caller
 * wins the canary slot and probes the provider. A successful probe resets
 * the health state (recorder markSuccess) and the breaker closes; a failed
 * probe re-arms the cooldown. Transient categories (timeout/network/rate
 * limit) never trip the breaker — the queue's bounded backoff handles them.
 */
class ProviderCircuitBreaker
{
    public function shouldSkip(string $source): bool
    {
        if (! config('qds.ingestion.circuit_breaker.enabled')) {
            return false;
        }

        $state = ProviderHealthState::query()->where('source', $source)->first();

        if ($state === null || $state->status !== ProviderStatus::Failing) {
            return false;
        }

        if ($state->last_error_category === null || $state->last_error_category->isTransient()) {
            return false;
        }

        if ($state->last_failure_at === null) {
            return false;
        }

        $cooldownMinutes = max(1, (int) config('qds.ingestion.circuit_breaker.cooldown_minutes'));

        if ($state->last_failure_at->addMinutes($cooldownMinutes)->isFuture()) {
            return true;
        }

        // Cooldown over — one canary probe per cooldown window goes
        // through (Cache::add is atomic: first caller wins), everyone else
        // keeps skipping until the probe's outcome lands in health state.
        return ! Cache::add(
            "qds:circuit-probe:{$source}",
            $state->last_failure_at->toIso8601String(),
            $cooldownMinutes * 60,
        );
    }
}
