<?php

namespace App\Platform\Enrichment\VisualMatch\Matching;

use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Shared\Enums\VisualMatchBand;

/**
 * One candidate's band decision (spec §8) plus everything the writer,
 * recorder, and reviewers need to see WHY: the review-strength-or-better
 * evidence frames, the dedup-aware AUTO support counter, the runner-up
 * margin, and the visibility evidence (null when no supporting frame
 * carries a timestamp). rejectionReason ∈ {'below-review-threshold'
 * (REJECT), 'margin-ambiguous' (REVIEW with AUTO support but failed
 * margin), null}.
 */
final readonly class BandResult
{
    /** @param list<FrameScore> $supportingFrames */
    public function __construct(
        public Candidate $candidate,
        public VisualMatchBand $band,
        public array $supportingFrames,
        public int $supportCount,
        public ?float $marginToRunnerUp,
        public ?string $rejectionReason,
        public ?int $firstSupportMs,
        public ?int $lastSupportMs,
        public ?int $estimatedVisibleMs,
        public float $bestSimilarity,
    ) {}
}
