<?php

namespace App\Platform\Ingestion\Observability;

use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Platform\Ingestion\Models\ProviderHealthState;
use App\Platform\Ingestion\Models\QuarantinedRecord;
use App\Platform\Ingestion\Persistence\PersistenceResult;
use App\Platform\Ingestion\Support\AlertType;
use App\Platform\Ingestion\Support\CallOutcome;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Platform\Ingestion\Support\PayloadRedactor;
use App\Platform\Ingestion\Support\ProviderStatus;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The single sink for external-call observability (External API
 * Monitoring): every provider call — success, partial, or failure — is
 * persisted as one ProviderCall row, updates the provider's health state,
 * quarantines rejected records, samples the (redacted) response, and
 * raises deduplicated alerts. Everything stored here is sanitized.
 */
class ProviderCallRecorder
{
    public function __construct(
        private readonly AlertService $alerts,
        private readonly ResponseSampler $sampler,
        private readonly PayloadRedactor $redactor,
    ) {}

    public function start(
        string $source,
        string $operation,
        string $correlationId,
        ?string $jobId = null,
        ?int $platformAccountId = null,
        int $retryCount = 0,
    ): CallContext {
        return new CallContext($source, $operation, $correlationId, $jobId, $platformAccountId, $retryCount);
    }

    /**
     * Record a call that returned a response (fully or partially usable).
     */
    public function recordCompletion(
        CallContext $context,
        NormalizedBatch $batch,
        PersistenceResult $persistence,
    ): ProviderCall {
        $quarantined = $this->quarantine($context, $batch);

        $rejectedCount = count($batch->rejected);

        $outcome = $rejectedCount > 0 ? CallOutcome::Partial : CallOutcome::Success;

        // Empty result where the call itself succeeded may be legitimate
        // (quiet account) — recorded as-is; unexpected emptiness is judged
        // by RefreshIngestionStatusJob against staleness thresholds.
        $call = ProviderCall::query()->create([
            'source' => $context->source,
            'operation' => $context->operation,
            'correlation_id' => $context->correlationId,
            'job_id' => $context->jobId,
            'platform_account_id' => $context->platformAccountId,
            'started_at' => $context->startedAt,
            'finished_at' => CarbonImmutable::now(),
            'duration_ms' => round($context->elapsedMs(), 2),
            'http_status' => $batch->response->httpStatus,
            'outcome' => $outcome,
            'error_category' => $rejectedCount > 0 ? $this->dominantRejectionCategory($batch) : null,
            'error_message' => null,
            'retry_count' => $context->retryCount,
            'response_bytes' => $batch->response->responseBytes,
            'result_count' => count($batch->response->items),
            'accepted_count' => count($batch->items),
            'rejected_count' => $rejectedCount,
            'duplicate_count' => $persistence->duplicates,
            'quarantined_count' => $quarantined,
            'rate_limit' => $batch->response->rateLimit ?: null,
            'timings' => [
                'request_ms' => round($batch->response->requestMs, 2),
                'validation_ms' => round($batch->validationMs, 2),
                'normalization_ms' => round($batch->normalizationMs, 2),
                'persistence_ms' => round($persistence->persistenceMs, 2),
                'media_ms' => round($persistence->mediaMs, 2),
            ],
        ]);

        $this->markSuccess($context, $outcome);
        $this->raiseSchemaDriftAlertIfNeeded($context, $batch);
        $this->raiseDurationAlertIfNeeded($context);

        $this->sampler->maybeSample($context->source, $context->operation, $context->correlationId, $batch->response);

        return $call;
    }

    /**
     * Record a call that failed outright (no usable response).
     */
    public function recordFailure(CallContext $context, Throwable $exception): ProviderCall
    {
        $category = $exception instanceof ProviderCallException
            ? $exception->category
            : ErrorCategory::Unknown;

        // Only classified, sanitized messages are stored; anything else is
        // reduced to its class name so raw provider errors never leak.
        $message = $exception instanceof ProviderCallException
            ? $this->redactor->redactString($exception->getMessage())
            : 'Unclassified failure: '.get_class($exception);

        $call = ProviderCall::query()->create([
            'source' => $context->source,
            'operation' => $context->operation,
            'correlation_id' => $context->correlationId,
            'job_id' => $context->jobId,
            'platform_account_id' => $context->platformAccountId,
            'started_at' => $context->startedAt,
            'finished_at' => CarbonImmutable::now(),
            'duration_ms' => round($context->elapsedMs(), 2),
            'http_status' => $exception instanceof ProviderCallException ? $exception->httpStatus : null,
            'outcome' => CallOutcome::Failure,
            'error_category' => $category,
            'error_message' => $message,
            'retry_count' => $context->retryCount,
            'rate_limit' => $exception instanceof ProviderCallException && $exception->retryAfterSeconds !== null
                ? ['retry_after' => $exception->retryAfterSeconds]
                : null,
        ]);

        $this->markFailure($context, $category, $message);
        $this->raiseRetryAlertIfNeeded($context);

        return $call;
    }

    private function quarantine(CallContext $context, NormalizedBatch $batch): int
    {
        $retentionDays = max(1, (int) config('qds.ingestion.quarantine_retention_days'));

        foreach ($batch->rejected as $rejected) {
            QuarantinedRecord::query()->create([
                'source' => $context->source,
                'operation' => $context->operation,
                'correlation_id' => $context->correlationId,
                'external_hint' => $rejected->externalHint,
                'reason_category' => $rejected->category,
                'reason' => $this->redactor->redactString($rejected->reason),
                'payload' => $this->redactor->redact($rejected->payload),
                'expires_at' => CarbonImmutable::now()->addDays($retentionDays),
            ]);
        }

        return count($batch->rejected);
    }

    private function dominantRejectionCategory(NormalizedBatch $batch): ErrorCategory
    {
        $counts = [];

        foreach ($batch->rejected as $rejected) {
            $counts[$rejected->category->value] = ($counts[$rejected->category->value] ?? 0) + 1;
        }

        arsort($counts);

        return ErrorCategory::from((string) array_key_first($counts));
    }

    private function markSuccess(CallContext $context, CallOutcome $outcome): void
    {
        ProviderHealthState::query()->updateOrCreate(
            ['source' => $context->source],
            [
                'status' => $outcome === CallOutcome::Success ? ProviderStatus::Healthy : ProviderStatus::Degraded,
                'last_success_at' => CarbonImmutable::now(),
                'consecutive_failures' => 0,
                'last_error_category' => null,
                'last_error_message' => null,
            ],
        );

        $this->alerts->resolve(AlertType::RepeatedFailures, $context->source);
    }

    private function markFailure(CallContext $context, ErrorCategory $category, string $message): void
    {
        /** @var ProviderHealthState $state */
        $state = ProviderHealthState::query()->firstOrCreate(
            ['source' => $context->source],
            ['status' => ProviderStatus::Unknown, 'consecutive_failures' => 0],
        );

        $failures = $state->consecutive_failures + 1;
        $threshold = max(1, (int) config('qds.ingestion.observability.failing_after_consecutive_failures'));

        $state->update([
            'status' => $failures >= $threshold ? ProviderStatus::Failing : ProviderStatus::Degraded,
            'last_failure_at' => CarbonImmutable::now(),
            'consecutive_failures' => $failures,
            'last_error_category' => $category,
            'last_error_message' => $message,
        ]);

        if ($failures >= $threshold) {
            $this->alerts->raise(
                AlertType::RepeatedFailures,
                $context->source,
                "{$context->source} has failed {$failures} consecutive calls (latest: {$category->value}).",
                'critical',
            );
        }
    }

    private function raiseSchemaDriftAlertIfNeeded(CallContext $context, NormalizedBatch $batch): void
    {
        $driftCategories = [ErrorCategory::SchemaDrift, ErrorCategory::MissingRequiredFields, ErrorCategory::InvalidFieldTypes];

        $drifted = count(array_filter(
            $batch->rejected,
            fn ($r): bool => in_array($r->category, $driftCategories, true),
        ));

        $total = count($batch->response->items);

        if ($total === 0 || $drifted === 0) {
            return;
        }

        $ratio = $drifted / $total;
        $threshold = (float) config('qds.ingestion.observability.schema_drift_alert_ratio');

        if ($ratio >= $threshold) {
            $this->alerts->raise(
                AlertType::SchemaDrift,
                $context->source,
                sprintf(
                    '%s: %d of %d records failed structural validation in one call — probable provider schema change.',
                    $context->source,
                    $drifted,
                    $total,
                ),
                'critical',
            );
        }
    }

    private function raiseDurationAlertIfNeeded(CallContext $context): void
    {
        $thresholdMs = (int) config('qds.ingestion.observability.abnormal_duration_ms');

        if ($context->elapsedMs() >= $thresholdMs) {
            $this->alerts->raise(
                AlertType::AbnormalDuration,
                $context->source,
                sprintf('%s [%s] took %.0f ms (threshold %d ms).', $context->source, $context->operation, $context->elapsedMs(), $thresholdMs),
            );
        }
    }

    private function raiseRetryAlertIfNeeded(CallContext $context): void
    {
        $threshold = (int) config('qds.ingestion.observability.excessive_retry_count');

        if ($context->retryCount >= $threshold) {
            $this->alerts->raise(
                AlertType::ExcessiveRetries,
                $context->source,
                sprintf('%s [%s] is on retry %d (threshold %d).', $context->source, $context->operation, $context->retryCount, $threshold),
            );
        }
    }
}
