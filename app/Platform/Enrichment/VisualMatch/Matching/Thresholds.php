<?php

namespace App\Platform\Enrichment\VisualMatch\Matching;

/**
 * The resolved band thresholds for one candidate's category (spec §8):
 * auto = similarity floor for AUTO support, review = floor for REVIEW
 * evidence, margin = how far the top candidate must beat the runner-up.
 */
final readonly class Thresholds
{
    public function __construct(
        public float $auto,
        public float $review,
        public float $margin,
    ) {}
}
