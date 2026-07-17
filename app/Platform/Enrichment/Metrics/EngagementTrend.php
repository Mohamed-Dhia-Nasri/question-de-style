<?php

namespace App\Platform\Enrichment\Metrics;

/**
 * MET-EngagementTrend (ADR-0024, tier DERIVED): average observed
 * likes + comments per post over the last N days vs the N days before,
 * as a whole signed percent. Exists only when BOTH windows contain
 * observed engagement and the previous average is non-zero.
 */
final readonly class EngagementTrend
{
    public function __construct(
        public float $currentAverage,
        public float $previousAverage,
        public int $percentChange,
        public int $currentCount,
        public int $previousCount,
    ) {}
}
