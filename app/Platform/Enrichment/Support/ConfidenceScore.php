<?php

namespace App\Platform\Enrichment\Support;

use App\Shared\Enums\ConfidenceLevel;

/**
 * Maps a numeric provider confidence score (0..1, e.g. Google Vision logo
 * score) onto the canonical ENUM-ConfidenceLevel. The cut-points are NOT
 * canonically decided (flagged missing decision) — they live in
 * config/qds.php `qds.enrichment.confidence` until an ADR fixes them.
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
