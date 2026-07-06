<?php

namespace App\Platform\Ingestion\Jobs\Concerns;

use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Models\IngestionCycle;
use App\Platform\Ingestion\Observability\AlertService;
use App\Platform\Ingestion\Support\AlertType;
use App\Platform\Ingestion\Support\CycleStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared behaviour for ingestion queue jobs: exponential backoff, rate-limit
 * releases, correlation-id log context, monitoring-cycle slot bookkeeping,
 * and failed-job visibility (a deduplicated JOB_FAILED alert on final
 * failure, on top of Laravel's failed_jobs table).
 *
 * Jobs carry ONLY scalar identifiers (ids, handles, URLs) — never raw
 * provider payloads or media blobs (ingestion spec, queues section).
 */
trait IngestionJobBehaviour
{
    /**
     * Exponential backoff between retries (seconds).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    protected function attachLogContext(): void
    {
        Log::withContext([
            'correlation_id' => $this->correlationId,
            'job' => static::class,
        ]);
    }

    /**
     * Shared handling for a classified provider failure:
     * - rate-limited with a provider-stated delay → release for that long;
     * - transient (timeout/network/upstream) → rethrow so the queue retries
     *   with exponential backoff;
     * - permanent (auth/malformed/schema) → fail the job now; retrying a
     *   permanent failure only burns provider budget.
     *
     * The observability recording has already happened by the time this is
     * called. Cycle-slot completion happens in failed() — exactly once.
     */
    protected function handleProviderFailure(Throwable $e): void
    {
        if ($e instanceof ProviderCallException
            && $e->retryAfterSeconds !== null
            && $this->attempts() < $this->tries) {
            $this->release($e->retryAfterSeconds);

            return;
        }

        if ($e instanceof ProviderCallException && ! $e->category->isTransient()) {
            $this->fail($e);

            return;
        }

        throw $e;
    }

    /**
     * Mark this job's slot in the monitoring cycle done; finalize the cycle
     * when the last slot completes. Race-safe via row lock.
     */
    protected function completeCycleSlot(bool $failed): void
    {
        if ($this->cycleId === null) {
            return;
        }

        DB::transaction(function () use ($failed): void {
            /** @var IngestionCycle|null $cycle */
            $cycle = IngestionCycle::query()->lockForUpdate()->find($this->cycleId);

            if ($cycle === null || ! $cycle->isRunning()) {
                return;
            }

            $pending = max(0, $cycle->jobs_pending - 1);
            $jobsFailed = $cycle->jobs_failed + ($failed ? 1 : 0);

            $cycle->update([
                'jobs_pending' => $pending,
                'jobs_failed' => $jobsFailed,
                ...($pending === 0 ? [
                    'status' => $jobsFailed > 0 ? CycleStatus::Partial : CycleStatus::Completed,
                    'finished_at' => CarbonImmutable::now(),
                ] : []),
            ]);
        });
    }

    /** Final-failure hook: cycle bookkeeping + deduplicated visibility. */
    public function failed(?Throwable $exception): void
    {
        $this->completeCycleSlot(failed: true);

        app(AlertService::class)->raise(
            AlertType::JobFailed,
            property_exists($this, 'source') && is_string($this->source) ? $this->source : null,
            sprintf(
                '%s failed permanently (correlation %s): %s',
                class_basename(static::class),
                $this->correlationId,
                $exception instanceof ProviderCallException
                    ? $exception->getMessage()
                    : ($exception === null ? 'unknown cause' : get_class($exception)),
            ),
            'critical',
        );
    }
}
