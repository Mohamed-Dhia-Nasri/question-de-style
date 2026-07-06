<?php

namespace App\Platform\Ingestion\DTO;

use App\Platform\Ingestion\Support\ErrorCategory;

/**
 * One raw provider record that failed validation/normalization and must be
 * quarantined rather than silently stored (ingestion spec: "Invalid
 * responses must not be silently stored"). The payload here is RAW — it is
 * redacted by the quarantine writer before persistence.
 */
final readonly class RejectedRecord
{
    public function __construct(
        public ErrorCategory $category,
        /** Sanitized, human-readable reason (no secrets, no raw payloads). */
        public string $reason,
        /** @var array<array-key, mixed> raw item — redact before storing */
        public array $payload,
        /** Best-effort external id of the offending item, if present. */
        public ?string $externalHint = null,
    ) {}
}
