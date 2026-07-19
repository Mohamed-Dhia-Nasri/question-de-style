<?php

namespace Tests\Unit\Enrichment;

use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateSet;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparationResult;
use App\Platform\Enrichment\VisualMatch\Frames\PreparedFrame;
use App\Platform\Enrichment\VisualMatch\Matching\BandMapper;
use App\Platform\Enrichment\VisualMatch\Matching\BandResult;
use App\Platform\Enrichment\VisualMatch\Matching\CandidateScores;
use App\Platform\Enrichment\VisualMatch\Matching\FrameScore;
use App\Platform\Enrichment\VisualMatch\Matching\ThresholdResolver;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\Enums\VisualMatchOutcome;
use Tests\TestCase;

/**
 * Spec §8 band rules, exhaustively: dedup-aware support counting
 * (timestamped groups count represented frames; null-timestamp groups
 * count once per distinct visual), the single-frame REVIEW cap, runner-up
 * margin ambiguity, category thresholds, ranking determinism, visibility
 * evidence, and the run-level NO_MATCH vs INCONCLUSIVE split.
 */
class BandMapperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('qds.enrichment.visual_match.thresholds', [
            'default' => ['auto' => 0.65, 'review' => 0.55, 'margin' => 0.05],
            'BEAUTY' => ['auto' => 0.70],
            'FOOD_BEVERAGE' => ['auto' => 0.70],
        ]);
    }

    public function test_two_distinct_timestamped_frames_at_auto_strength_band_auto(): void
    {
        $scored = [new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, 0, 0.80),
            $this->frameScore(11, 1, 6000, 0.70),
        ])];
        $prep = $this->prep([$this->frame(10, 0), $this->frame(11, 6000)]);

        $results = $this->mapper()->map($scored, $prep);

        $this->assertCount(1, $results);
        $result = $results[0];
        $this->assertSame(VisualMatchBand::Auto, $result->band);
        $this->assertSame(2, $result->supportCount);
        $this->assertNull($result->marginToRunnerUp); // alone: nothing to be ambiguous against
        $this->assertNull($result->rejectionReason);
        $this->assertEqualsWithDelta(0.80, $result->bestSimilarity, 1e-9);
        $this->assertCount(2, $result->supportingFrames);
        // Visibility: span 0–6000 ms; sampling span 6000/(2-1); 2 supported
        // represented frames ≈ 12 s visible.
        $this->assertSame(0, $result->firstSupportMs);
        $this->assertSame(6000, $result->lastSupportMs);
        $this->assertSame(12000, $result->estimatedVisibleMs);
    }

    public function test_a_video_dedup_group_contributes_its_full_represented_count(): void
    {
        // One representative for a near-identical 0–12 s span of 3 sampled
        // frames: repeated visibility, not one isolated moment (spec §8).
        $scored = [new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, 0, 0.80, representedFrames: 3),
        ])];
        $prep = $this->prep(
            [$this->frame(10, 0, representedFrames: 3, spanEndMs: 12000)],
            framesAvailable: 3,
            deduped: 2,
        );

        $result = $this->mapper()->map($scored, $prep)[0];

        $this->assertSame(VisualMatchBand::Auto, $result->band);
        $this->assertSame(3, $result->supportCount);
        $this->assertSame(0, $result->firstSupportMs);
        // The group's span end, not its representative timestamp.
        $this->assertSame(12000, $result->lastSupportMs);
        // Sampling span = 12000/(3-1) = 6000 ms; 3 × 6000 = 18 s.
        $this->assertSame(18000, $result->estimatedVisibleMs);
    }

    public function test_a_null_timestamp_group_counts_once_no_matter_its_size(): void
    {
        // Two identical uploaded carousel images are ONE piece of evidence —
        // the single-frame REVIEW cap by construction (spec §8).
        $scored = [new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, null, 0.90, representedFrames: 3),
        ])];
        $prep = $this->prep([$this->frame(10, null, representedFrames: 3)], framesAvailable: 3, deduped: 2);

        $result = $this->mapper()->map($scored, $prep)[0];

        $this->assertSame(VisualMatchBand::Review, $result->band);
        $this->assertSame(1, $result->supportCount);
        $this->assertNull($result->rejectionReason);
        $this->assertNull($result->firstSupportMs);
        $this->assertNull($result->lastSupportMs);
        $this->assertNull($result->estimatedVisibleMs);
    }

    public function test_two_distinct_null_timestamp_visuals_reach_auto(): void
    {
        // Two DIFFERENT carousel images showing the product are two pieces
        // of evidence (dedup already collapsed identical ones).
        $scored = [new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, null, 0.80),
            $this->frameScore(11, 1, null, 0.75),
        ])];
        $prep = $this->prep([$this->frame(10, null), $this->frame(11, null)]);

        $result = $this->mapper()->map($scored, $prep)[0];

        $this->assertSame(VisualMatchBand::Auto, $result->band);
        $this->assertSame(2, $result->supportCount);
        $this->assertNull($result->estimatedVisibleMs);
    }

    public function test_ambiguous_margin_demotes_auto_support_to_review_for_both(): void
    {
        $scoredA = new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, 0, 0.72),
            $this->frameScore(11, 1, 6000, 0.70),
        ]);
        $scoredB = new CandidateScores($this->candidate(2), [
            $this->frameScore(10, 0, 0, 0.70),
            $this->frameScore(11, 1, 6000, 0.69),
        ]);
        $prep = $this->prep([$this->frame(10, 0), $this->frame(11, 6000)]);

        $results = $this->mapper()->map([$scoredA, $scoredB], $prep);

        $this->assertSame(1, $results[0]->candidate->productId);
        $this->assertSame(2, $results[1]->candidate->productId);
        // 0.72 beats 0.70 by only 0.02 < margin 0.05 → nobody auto-matches.
        $this->assertSame(VisualMatchBand::Review, $results[0]->band);
        $this->assertSame('margin-ambiguous', $results[0]->rejectionReason);
        $this->assertEqualsWithDelta(0.02, $results[0]->marginToRunnerUp, 1e-6);
        $this->assertSame(VisualMatchBand::Review, $results[1]->band);
        $this->assertSame('margin-ambiguous', $results[1]->rejectionReason);
        $this->assertEqualsWithDelta(-0.02, $results[1]->marginToRunnerUp, 1e-6);
    }

    public function test_clear_margin_top_auto_runner_up_stays_review(): void
    {
        $scoredA = new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, 0, 0.85),
            $this->frameScore(11, 1, 6000, 0.80),
        ]);
        $scoredB = new CandidateScores($this->candidate(2), [
            $this->frameScore(10, 0, 0, 0.66),
            $this->frameScore(11, 1, 6000, 0.66),
        ]);
        $prep = $this->prep([$this->frame(10, 0), $this->frame(11, 6000)]);

        // Input order must not matter: pass runner-up first.
        $results = $this->mapper()->map([$scoredB, $scoredA], $prep);

        $this->assertSame(1, $results[0]->candidate->productId);
        $this->assertSame(VisualMatchBand::Auto, $results[0]->band);
        $this->assertEqualsWithDelta(0.19, $results[0]->marginToRunnerUp, 1e-6);
        // The runner-up has AUTO-grade support of its own but loses the
        // margin — REVIEW, never a second AUTO.
        $this->assertSame(VisualMatchBand::Review, $results[1]->band);
        $this->assertSame('margin-ambiguous', $results[1]->rejectionReason);
    }

    public function test_margin_exactly_at_threshold_is_enough(): void
    {
        $scoredA = new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, 0, 0.70),
            $this->frameScore(11, 1, 6000, 0.70),
        ]);
        $scoredB = new CandidateScores($this->candidate(2), [
            $this->frameScore(10, 0, 0, 0.65),
        ]);
        $prep = $this->prep([$this->frame(10, 0), $this->frame(11, 6000)]);

        $results = $this->mapper()->map([$scoredA, $scoredB], $prep);

        // 0.70 - 0.65 = 0.05 ≥ margin 0.05 (float dust neutralized by
        // rounding — 0.70-0.65 is 0.049999… in IEEE 754).
        $this->assertSame(VisualMatchBand::Auto, $results[0]->band);
        $this->assertEqualsWithDelta(0.05, $results[0]->marginToRunnerUp, 1e-6);
    }

    public function test_one_strong_frame_is_never_auto_and_never_rejected(): void
    {
        $scored = [new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, 0, 0.90),
            $this->frameScore(11, 1, 6000, 0.30),
        ])];
        $prep = $this->prep([$this->frame(10, 0), $this->frame(11, 6000)]);

        $result = $this->mapper()->map($scored, $prep)[0];

        // The locked "never auto-accept one isolated match" rule — and a
        // lone strong hit is never auto-rejected either: it lands REVIEW.
        $this->assertSame(VisualMatchBand::Review, $result->band);
        $this->assertSame(1, $result->supportCount);
        $this->assertNull($result->rejectionReason);
        // Only the strong frame supports (0.30 < review 0.55).
        $this->assertCount(1, $result->supportingFrames);
        $this->assertSame(10, $result->supportingFrames[0]->keyframeId);
    }

    public function test_mid_band_evidence_lands_review(): void
    {
        $scored = [new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, 0, 0.60),
        ])];
        $prep = $this->prep([$this->frame(10, 0)]);

        $result = $this->mapper()->map($scored, $prep)[0];

        $this->assertSame(VisualMatchBand::Review, $result->band);
        $this->assertSame(0, $result->supportCount); // review strength is not AUTO support
        $this->assertCount(1, $result->supportingFrames);
    }

    public function test_below_review_threshold_rejects(): void
    {
        $scored = [new CandidateScores($this->candidate(1), [
            $this->frameScore(10, 0, 0, 0.40),
        ])];
        $prep = $this->prep([$this->frame(10, 0)]);

        $result = $this->mapper()->map($scored, $prep)[0];

        $this->assertSame(VisualMatchBand::Reject, $result->band);
        $this->assertSame('below-review-threshold', $result->rejectionReason);
        $this->assertSame([], $result->supportingFrames);
        $this->assertEqualsWithDelta(0.40, $result->bestSimilarity, 1e-9);
    }

    public function test_category_override_raises_the_auto_bar(): void
    {
        $frames = [
            $this->frameScore(10, 0, 0, 0.67),
            $this->frameScore(11, 1, 6000, 0.67),
        ];
        $prep = $this->prep([$this->frame(10, 0), $this->frame(11, 6000)]);

        // BEAUTY (packaging-prone): auto raised to 0.70 → same evidence
        // only reaches REVIEW.
        $beauty = $this->mapper()->map([new CandidateScores($this->candidate(1, SectorLabel::Beauty), $frames)], $prep)[0];
        $this->assertSame(VisualMatchBand::Review, $beauty->band);
        $this->assertSame(0, $beauty->supportCount);

        // Uncategorized product: default auto 0.65 → AUTO.
        $default = $this->mapper()->map([new CandidateScores($this->candidate(1), $frames)], $prep)[0];
        $this->assertSame(VisualMatchBand::Auto, $default->band);
        $this->assertSame(2, $default->supportCount);
    }

    public function test_ranking_is_best_first_ties_to_lower_product_id(): void
    {
        $prep = $this->prep([$this->frame(10, 0)]);
        $scored = [
            new CandidateScores($this->candidate(9), [$this->frameScore(10, 0, 0, 0.30)]),
            new CandidateScores($this->candidate(4), [$this->frameScore(10, 0, 0, 0.60)]),
            new CandidateScores($this->candidate(2), [$this->frameScore(10, 0, 0, 0.60)]),
        ];

        $results = $this->mapper()->map($scored, $prep);

        $this->assertSame([2, 4, 9], array_map(fn (BandResult $r): int => $r->candidate->productId, $results));
        // Determinism: identical inputs ⇒ identical output.
        $this->assertEquals($results, $this->mapper()->map($scored, $prep));
    }

    public function test_run_outcome_matched_wins_over_review(): void
    {
        $prep = $this->prep([$this->frame(10, 0), $this->frame(11, 6000)]);
        $candidates = new CandidateSet([$this->candidate(1), $this->candidate(2)], Priority::High);
        $results = $this->mapper()->map([
            new CandidateScores($this->candidate(1), [
                $this->frameScore(10, 0, 0, 0.85),
                $this->frameScore(11, 1, 6000, 0.80),
            ]),
            new CandidateScores($this->candidate(2), [$this->frameScore(10, 0, 0, 0.60)]),
        ], $prep);

        $this->assertSame(VisualMatchOutcome::Matched, $this->mapper()->runOutcome($results, $prep, $candidates));
    }

    public function test_run_outcome_review_when_no_auto(): void
    {
        $prep = $this->prep([$this->frame(10, 0)]);
        $candidates = new CandidateSet([$this->candidate(1)], Priority::High);
        $results = $this->mapper()->map([
            new CandidateScores($this->candidate(1), [$this->frameScore(10, 0, 0, 0.90)]),
        ], $prep);

        $this->assertSame(VisualMatchOutcome::Review, $this->mapper()->runOutcome($results, $prep, $candidates));
    }

    public function test_run_outcome_splits_no_match_from_inconclusive_on_coverage(): void
    {
        $candidates = new CandidateSet([$this->candidate(1)], Priority::High);
        $rejects = fn (FramePreparationResult $prep): array => $this->mapper()->map([
            new CandidateScores($this->candidate(1), [$this->frameScore(10, 0, 0, 0.20)]),
        ], $prep);

        // Clean coverage: every stored frame was processed → "we looked
        // properly and did not see it".
        $clean = $this->prep([$this->frame(10, 0)]);
        $this->assertSame(VisualMatchOutcome::NoMatch, $this->mapper()->runOutcome($rejects($clean), $clean, $candidates));

        // A quality-skipped frame: we may not have looked properly —
        // unavailable ≠ false.
        $degraded = $this->prep([$this->frame(10, 0)], framesAvailable: 2, skippedQuality: 1);
        $this->assertSame(VisualMatchOutcome::Inconclusive, $this->mapper()->runOutcome($rejects($degraded), $degraded, $candidates));

        // Zero frames survived preparation.
        $empty = $this->prep([], framesAvailable: 1, skippedFormat: 1);
        $this->assertSame(VisualMatchOutcome::Inconclusive, $this->mapper()->runOutcome([], $empty, $candidates));
    }

    public function test_run_outcome_inconclusive_when_candidates_exist_but_none_matchable(): void
    {
        $prep = $this->prep([$this->frame(10, 0)]);
        $unmatchable = new Candidate(
            productId: 7,
            productLabel: 'Product 7',
            brandName: 'Nexon Labs',
            category: null,
            source: 'roster',
            shipmentInWindow: false,
            seedingCampaignId: 3,
            shipmentAnchorAt: null,
            shipmentAgeDays: null,
            hasEmbeddedPhotos: false,
        );

        // Candidates existed but had no embedded reference photos: we never
        // actually compared anything — INCONCLUSIVE, not a clean NO_MATCH.
        $outcome = $this->mapper()->runOutcome([], $prep, new CandidateSet([$unmatchable], Priority::Medium));

        $this->assertSame(VisualMatchOutcome::Inconclusive, $outcome);
    }

    private function mapper(): BandMapper
    {
        return new BandMapper(new ThresholdResolver);
    }

    private function candidate(int $productId, ?SectorLabel $category = null): Candidate
    {
        return new Candidate(
            productId: $productId,
            productLabel: "Product {$productId}",
            brandName: 'Nexon Labs',
            category: $category,
            source: 'shipment',
            shipmentInWindow: true,
            seedingCampaignId: null,
            shipmentAnchorAt: null,
            shipmentAgeDays: 12,
            hasEmbeddedPhotos: true,
        );
    }

    private function frameScore(int $keyframeId, int $ordinal, ?int $timestampMs, float $similarity, int $representedFrames = 1): FrameScore
    {
        return new FrameScore($keyframeId, $ordinal, $timestampMs, $similarity, 900 + $keyframeId, $representedFrames);
    }

    private function keyframe(int $id, ?int $timestampMs): Keyframe
    {
        $keyframe = new Keyframe;
        $keyframe->id = $id;
        $keyframe->timestamp_ms = $timestampMs;

        return $keyframe;
    }

    private function frame(int $keyframeId, ?int $timestampMs, int $representedFrames = 1, ?int $spanEndMs = null): PreparedFrame
    {
        return new PreparedFrame(
            keyframe: $this->keyframe($keyframeId, $timestampMs),
            bytes: '',
            mimeType: 'image/jpeg',
            representedFrames: $representedFrames,
            spanStartMs: $timestampMs,
            spanEndMs: $spanEndMs ?? $timestampMs,
        );
    }

    /** @param list<PreparedFrame> $frames */
    private function prep(array $frames, ?int $framesAvailable = null, int $skippedFormat = 0, int $skippedQuality = 0, int $deduped = 0): FramePreparationResult
    {
        return new FramePreparationResult(
            frames: $frames,
            framesAvailable: $framesAvailable ?? count($frames),
            skippedFormat: $skippedFormat,
            skippedQuality: $skippedQuality,
            deduped: $deduped,
        );
    }
}
