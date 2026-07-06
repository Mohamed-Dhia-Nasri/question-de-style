<?php

namespace App\Platform\Ingestion\Observability;

use Carbon\CarbonImmutable;

/**
 * In-memory context for one provider call, opened before the request and
 * closed (persisted as a ProviderCall row) exactly once — on success or
 * failure.
 */
final class CallContext
{
    public readonly CarbonImmutable $startedAt;

    private readonly float $startedAtMono;

    public function __construct(
        public readonly string $source,
        public readonly string $operation,
        public readonly string $correlationId,
        public readonly ?string $jobId = null,
        public readonly ?int $platformAccountId = null,
        /** Queue attempt number minus one (0 on the first run). */
        public readonly int $retryCount = 0,
    ) {
        $this->startedAt = CarbonImmutable::now();
        $this->startedAtMono = microtime(true);
    }

    public function elapsedMs(): float
    {
        return (microtime(true) - $this->startedAtMono) * 1000;
    }
}
