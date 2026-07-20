<?php

namespace App\Platform\Enrichment\VisualMatch\Matching;

use App\Platform\Enrichment\VisualMatch\Candidates\CandidateSet;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparationResult;
use App\Platform\Enrichment\VisualMatch\Frames\PreparedFrame;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\Enums\VisualMatchOutcome;

/**
 * Pure scores → bands decision (spec §8): per-category thresholds,
 * dedup-aware support counting, runner-up margin, visibility evidence, and
 * the run-level NO_MATCH vs INCONCLUSIVE split. No I/O, no provider —
 * fully deterministic: identical embeddings + config ⇒ identical outcome;
 * ties break on lower product id. Confidence here is VISUAL-ONLY by
 * design (fusion is sub-project E's mandate).
 */
final class BandMapper
{
    public function __construct(private readonly ThresholdResolver $thresholds) {}

    /**
     * @param  list<CandidateScores>  $scored
     * @return list<BandResult> ranked best-first; ties broken by lower productId
     */
    public function map(array $scored, FramePreparationResult $prep): array
    {
        if ($scored === []) {
            return [];
        }

        $ranked = $scored;
        usort($ranked, fn (CandidateScores $a, CandidateScores $b): int => [$b->bestSimilarity(), $a->candidate->productId] <=> [$a->bestSimilarity(), $b->candidate->productId]);

        /** @var array<int, PreparedFrame> $frameByKeyframeId */
        $frameByKeyframeId = [];
        $maxObservedMs = null;
        foreach ($prep->frames as $frame) {
            $frameByKeyframeId[(int) $frame->keyframe->getKey()] = $frame;
            $end = $frame->spanEndMs ?? $frame->keyframe->timestamp_ms;
            if ($end !== null) {
                $maxObservedMs = max($maxObservedMs ?? 0, (int) $end);
            }
        }

        $results = [];
        foreach ($ranked as $index => $candidateScores) {
            $results[] = $this->bandFor($candidateScores, $ranked, $index, $frameByKeyframeId, $maxObservedMs, $prep->framesAvailable);
        }

        return $results;
    }

    /** @param list<BandResult> $bandResults */
    public function runOutcome(array $bandResults, FramePreparationResult $prep, CandidateSet $candidates): VisualMatchOutcome
    {
        $bands = array_map(fn (BandResult $result): VisualMatchBand => $result->band, $bandResults);

        if (in_array(VisualMatchBand::Auto, $bands, true)) {
            return VisualMatchOutcome::Matched;
        }

        if (in_array(VisualMatchBand::Review, $bands, true)) {
            return VisualMatchOutcome::Review;
        }

        // Nothing banded. Split "we looked properly and did not see it"
        // (clean NO_MATCH) from "we could not look properly" (INCONCLUSIVE
        // — unavailable ≠ false, spec §8/§11).
        if ($prep->coverageDegraded()) {
            return VisualMatchOutcome::Inconclusive;
        }

        if (! $candidates->isEmpty() && $candidates->matchable() === []) {
            // Candidates existed but none had embedded reference photos at
            // this model version: we never actually compared anything.
            return VisualMatchOutcome::Inconclusive;
        }

        return VisualMatchOutcome::NoMatch;
    }

    /**
     * @param  list<CandidateScores>  $ranked
     * @param  array<int, PreparedFrame>  $frameByKeyframeId
     */
    private function bandFor(
        CandidateScores $candidateScores,
        array $ranked,
        int $index,
        array $frameByKeyframeId,
        ?int $maxObservedMs,
        int $framesAvailable,
    ): BandResult {
        $candidate = $candidateScores->candidate;
        $thresholds = $this->thresholds->for($candidate->category);
        $best = $candidateScores->bestSimilarity();

        // Runner-up = the best similarity among the OTHER candidates; null
        // when this candidate stands alone (nothing to be ambiguous
        // against). Non-top candidates get a negative margin, so at most
        // one candidate can pass the AUTO margin gate.
        $runnerUp = null;
        foreach ($ranked as $otherIndex => $other) {
            if ($otherIndex !== $index) {
                $runnerUp = $runnerUp === null ? $other->bestSimilarity() : max($runnerUp, $other->bestSimilarity());
            }
        }
        // Rounded so an exactly-at-threshold margin never fails on IEEE 754 dust.
        $margin = $runnerUp === null ? null : round($best - $runnerUp, 6);

        // Supporting frames: everything at review strength or better — the
        // evidence trail reviewers and tier D ground against.
        $supporting = array_values(array_filter(
            $candidateScores->frameScores,
            fn (FrameScore $score): bool => $score->similarity >= $thresholds->review,
        ));

        // AUTO-rule support counter (dedup-aware, spec §8), auto-strength
        // frames only: a timestamped dedup group contributes its FULL
        // represented_frames count (a near-identical span IS repeated
        // visibility), a null-timestamp group (carousel/story image) counts
        // ONCE per distinct visual — identical uploads are one piece of
        // evidence, not two.
        $supportCount = 0;
        foreach ($candidateScores->frameScores as $score) {
            if ($score->similarity >= $thresholds->auto) {
                $supportCount += $score->timestampMs === null ? 1 : $score->representedFrames;
            }
        }

        [$firstMs, $lastMs, $visibleMs] = $this->visibility($supporting, $frameByKeyframeId, $maxObservedMs, $framesAvailable);

        if ($best < $thresholds->review) {
            return new BandResult($candidate, VisualMatchBand::Reject, $supporting, $supportCount,
                $margin, 'below-review-threshold', $firstMs, $lastMs, $visibleMs, $best);
        }

        if ($supportCount >= 2 && ($margin === null || $margin >= $thresholds->margin)) {
            return new BandResult($candidate, VisualMatchBand::Auto, $supporting, $supportCount,
                $margin, null, $firstMs, $lastMs, $visibleMs, $best);
        }

        // REVIEW: exactly one auto-strength frame, or review-band evidence
        // only, or AUTO support with an ambiguous margin. A lone strong hit
        // is never auto-rejected — it lands here for humans (and tier D).
        return new BandResult($candidate, VisualMatchBand::Review, $supporting, $supportCount,
            $margin, $supportCount >= 2 ? 'margin-ambiguous' : null, $firstMs, $lastMs, $visibleMs, $best);
    }

    /**
     * Visibility evidence (spec §8): first/last supported timestamp (dedup
     * group spans included) and estimated on-screen time ≈ supported
     * represented frames × the video's sampling span (duration ≈ the last
     * observed timestamp under B's even-interval extraction). All null when
     * no supporting frame carries a timestamp (images/stories).
     *
     * @param  list<FrameScore>  $supporting
     * @param  array<int, PreparedFrame>  $frameByKeyframeId
     * @return array{0: ?int, 1: ?int, 2: ?int}
     */
    private function visibility(array $supporting, array $frameByKeyframeId, ?int $maxObservedMs, int $framesAvailable): array
    {
        $first = null;
        $last = null;
        $represented = 0;

        foreach ($supporting as $score) {
            if ($score->timestampMs === null) {
                continue;
            }

            $frame = $frameByKeyframeId[$score->keyframeId] ?? null;
            $start = $frame?->spanStartMs ?? $score->timestampMs;
            $end = $frame?->spanEndMs ?? $score->timestampMs;
            $first = $first === null ? $start : min($first, $start);
            $last = $last === null ? $end : max($last, $end);
            $represented += $score->representedFrames;
        }

        if ($first === null || $maxObservedMs === null) {
            return [null, null, null];
        }

        $spanMs = $framesAvailable > 1 ? intdiv($maxObservedMs, $framesAvailable - 1) : $maxObservedMs;

        return [$first, $last, $represented * $spanMs];
    }
}
