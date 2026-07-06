<?php

namespace App\Platform\Enrichment\Support;

use App\Shared\Enums\VerificationStatus;
use App\Shared\ValueObjects\ConfidenceAssessment;

/**
 * DP-004 precedence rule, applied by every AI writer before touching an
 * existing envelope: once a human has reviewed, corrected, or confirmed a
 * value, no later AI run may overwrite it. Only UNVERIFIED and AI_ASSESSED
 * envelopes are AI-writable.
 */
final class HumanPrecedence
{
    public static function allowsAiUpdate(?ConfidenceAssessment $existing): bool
    {
        if ($existing === null) {
            return true;
        }

        return in_array($existing->verificationStatus, [
            VerificationStatus::Unverified,
            VerificationStatus::AiAssessed,
        ], true);
    }

    private function __construct() {}
}
