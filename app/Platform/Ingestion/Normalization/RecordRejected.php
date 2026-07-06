<?php

namespace App\Platform\Ingestion\Normalization;

use App\Platform\Ingestion\Support\ErrorCategory;
use RuntimeException;

/**
 * Thrown while normalizing ONE raw provider item; caught by the batch
 * normalizer, which converts it into a RejectedRecord bound for quarantine
 * (never a silent store, never a pipeline abort — other items proceed).
 * The message must be sanitized: it is persisted as the quarantine reason.
 */
class RecordRejected extends RuntimeException
{
    public function __construct(
        public readonly ErrorCategory $category,
        string $sanitizedReason,
        public readonly ?string $externalHint = null,
    ) {
        parent::__construct($sanitizedReason);
    }
}
