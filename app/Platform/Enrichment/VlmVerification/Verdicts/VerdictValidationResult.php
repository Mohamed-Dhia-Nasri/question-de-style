<?php

namespace App\Platform\Enrichment\VlmVerification\Verdicts;

/**
 * Outcome of validating one provider response (spec §6). Exactly one of
 * verdicts/malformedReason is non-null. A malformed result is the job's
 * bounded corrective-retry signal (≤ per_post_units billed calls total);
 * normalizedInconclusive records that the outcome↔verdict consistency
 * rule fired (PRODUCT_CONFIRMED with no confirming verdict →
 * INCONCLUSIVE) — recorded with signal vlm-outcome-normalized, NOT
 * retried.
 */
final readonly class VerdictValidationResult
{
    private function __construct(
        public ?VerdictSet $verdicts,
        public ?string $malformedReason,
        public bool $normalizedInconclusive,
    ) {}

    public static function valid(VerdictSet $verdicts, bool $normalizedInconclusive): self
    {
        return new self($verdicts, null, $normalizedInconclusive);
    }

    public static function malformed(string $reason): self
    {
        return new self(null, $reason, false);
    }
}
