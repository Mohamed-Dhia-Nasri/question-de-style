<?php

namespace App\Platform\Enrichment\VisualMatch\Matching;

use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;

/**
 * All per-frame best scores of one matchable candidate (sub-project C).
 */
final readonly class CandidateScores
{
    /** @param list<FrameScore> $frameScores best photo per frame, ordered by ordinal */
    public function __construct(
        public Candidate $candidate,
        public array $frameScores,
    ) {}

    /** Best similarity across all frames; 0.0 when no frames scored. */
    public function bestSimilarity(): float
    {
        if ($this->frameScores === []) {
            return 0.0;
        }

        return max(array_map(fn (FrameScore $score): float => $score->similarity, $this->frameScores));
    }
}
