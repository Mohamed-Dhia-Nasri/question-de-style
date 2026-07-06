<?php

namespace App\Platform\Ingestion\DTO;

/**
 * Raw, envelope-validated response of one provider call, before per-item
 * normalization. Produced by the HTTP clients (ApifyClient / YouTubeClient).
 */
final readonly class ProviderResponse
{
    public function __construct(
        /** @var list<mixed> raw result items (decoded JSON — items may be malformed) */
        public array $items,
        public int $httpStatus,
        public int $responseBytes,
        /** Milliseconds spent on the HTTP request itself. */
        public float $requestMs,
        /** Actor/API version identifier used as Provenance.sourceVersion. */
        public string $sourceVersion,
        /** @var array{remaining?: int|null, retry_after?: int|null} */
        public array $rateLimit = [],
    ) {}
}
