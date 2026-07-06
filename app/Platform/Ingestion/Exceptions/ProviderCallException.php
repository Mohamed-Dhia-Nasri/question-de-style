<?php

namespace App\Platform\Ingestion\Exceptions;

use App\Platform\Ingestion\Support\ErrorCategory;
use RuntimeException;

/**
 * A failed external provider call, already classified into a normalized
 * ErrorCategory and carrying only a SANITIZED message — safe to persist,
 * log, and surface to operators. Raw provider errors and secrets must never
 * leave the client layer (Security: provider errors are not exposed).
 */
class ProviderCallException extends RuntimeException
{
    public function __construct(
        public readonly string $source,
        public readonly ErrorCategory $category,
        string $sanitizedMessage,
        public readonly ?int $httpStatus = null,
        /** Seconds to wait before retrying, when the provider said so (429). */
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($sanitizedMessage);
    }
}
