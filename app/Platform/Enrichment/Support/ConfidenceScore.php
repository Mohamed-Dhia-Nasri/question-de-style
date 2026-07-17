<?php

namespace App\Platform\Enrichment\Support;

use App\Shared\Enums\ConfidenceLevel;

/**
 * Maps a numeric provider confidence score (0..1, e.g. Google Vision logo
 * score) onto the canonical ENUM-ConfidenceLevel. Cut-points are canonical
 * per ADR-0026 (0.85 / 0.60), env-tunable as operational calibration.
 * A missing/invalid score maps to UNKNOWN (never guessed).
 */
final class ConfidenceScore
{
    public static function toLevel(?float $score): ConfidenceLevel
    {
        if ($score === null || $score < 0.0 || $score > 1.0) {
            return ConfidenceLevel::Unknown;
        }

        $high = (float) config('qds.enrichment.confidence.high');
        $medium = (float) config('qds.enrichment.confidence.medium');

        return match (true) {
            $score >= $high => ConfidenceLevel::High,
            $score >= $medium => ConfidenceLevel::Medium,
            default => ConfidenceLevel::Low,
        };
    }

    private function __construct() {}
}
