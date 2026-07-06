<?php

namespace App\Platform\Ingestion\Http;

/**
 * One decoded JSON HTTP response plus the request metadata the
 * observability layer needs. Used by clients whose adapters make several
 * calls per logical operation (YouTube channel → uploads → videos).
 */
final readonly class RawJsonResponse
{
    public function __construct(
        /** @var array<array-key, mixed> decoded JSON body */
        public array $data,
        public int $httpStatus,
        public int $responseBytes,
        public float $requestMs,
    ) {}
}
