<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\VlmVerification\Banding\VlmBandMapper;
use App\Platform\Enrichment\VlmVerification\Banding\VlmBandResult;
use App\Platform\Enrichment\VlmVerification\Requests\VlmCandidate;
use App\Platform\Enrichment\VlmVerification\Requests\VlmFrame;
use App\Platform\Enrichment\VlmVerification\Requests\VlmRequest;
use App\Platform\Enrichment\VlmVerification\Verdicts\CandidateVerdict;
use App\Platform\Enrichment\VlmVerification\Verdicts\VerdictSet;
use App\Shared\Enums\VlmBand;
use Tests\TestCase;

/**
 * Spec §7 band rules, exhaustively: totality (every schema-valid verdict
 * lands in exactly one band), the visibility requirement (spoken/gifting
 * claims cap at REVIEW), the set-wise margin gate (at most one AUTO per
 * run), the §6 caption-echo cap, INCONCLUSIVE vs PRODUCT_ABSENT, threshold
 * config resolution, ranking determinism.
 */
class VlmBandMapperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('qds.enrichment.vlm.thresholds', [
            'auto' => 0.85, 'review' => 0.60, 'margin' => 0.10,
        ]);
    }

    public function test_confirmed_visible_confident_sole_candidate_bands_auto(): void
    {
        $results = $this->mapper()->map(
            $this->set('PRODUCT_CONFIRMED', $this->verdict(1, 0.91)),
            $this->request(),
        );

        $this->assertCount(1, $results);
        $this->assertSame(VlmBand::Auto, $results[0]->band);
        $this->assertNull($results[0]->rejectionReason);
        $this->assertFalse($results[0]->captionEcho);
        $this->assertSame(1, $results[0]->verdict->productId);
    }

    public function test_confidence_below_review_rejects_first(): void
    {
        // Rule order (§7): below-review fires before negative-claim and
        // before run-absent — even an all-false claim at 0.20 reports the
        // confidence rule.
        $results = $this->mapper()->map(
            $this->set(
                'PRODUCT_CONFIRMED',
                $this->verdict(1, 0.40),
                $this->verdict(2, 0.20, visible: false, spoken: false, giftingCue: false),
            ),
            $this->request(),
        );

        $this->assertSame(VlmBand::Reject, $results[0]->band);
        $this->assertSame('below-review-threshold', $results[0]->rejectionReason);
        $this->assertSame(VlmBand::Reject, $results[1]->band);
        $this->assertSame('below-review-threshold', $results[1]->rejectionReason);
    }

    public function test_negative_claim_rejects_even_when_confident(): void
    {
        $result = $this->mapper()->map(
            $this->set('PRODUCT_CONFIRMED', $this->verdict(1, 0.95, visible: false, spoken: false, giftingCue: false)),
            $this->request(),
        )[0];

        $this->assertSame(VlmBand::Reject, $result->band);
        $this->assertSame('negative-claim', $result->rejectionReason);
    }

    public function test_product_absent_run_rejects_positive_claims(): void
    {
        $results = $this->mapper()->map(
            $this->set('PRODUCT_ABSENT', $this->verdict(1, 0.90), $this->verdict(2, 0.30)),
            $this->request(),
        );

        $this->assertSame(VlmBand::Reject, $results[0]->band);
        $this->assertSame('run-absent', $results[0]->rejectionReason);
        // Rule order: the sub-review verdict reports the confidence rule.
        $this->assertSame('below-review-threshold', $results[1]->rejectionReason);
    }

    public function test_spoken_only_claim_caps_at_review(): void
    {
        // A visual grounding stage never auto-confirms unseen products
        // (spec §7): spoken=true alone caps at REVIEW no matter the
        // confidence.
        $result = $this->mapper()->map(
            $this->set('PRODUCT_CONFIRMED', $this->verdict(1, 0.95, visible: false, spoken: true)),
            $this->request(),
        )[0];

        $this->assertSame(VlmBand::Review, $result->band);
        $this->assertSame('not-visible', $result->rejectionReason);
    }

    public function test_gifting_cue_only_claim_caps_at_review(): void
    {
        $result = $this->mapper()->map(
            $this->set('PRODUCT_CONFIRMED', $this->verdict(1, 0.95, visible: false, giftingCue: true)),
            $this->request(),
        )[0];

        $this->assertSame(VlmBand::Review, $result->band);
        $this->assertSame('not-visible', $result->rejectionReason);
    }

    public function test_visible_without_frame_reference_caps_at_review(): void
    {
        $result = $this->mapper()->map(
            $this->set('PRODUCT_CONFIRMED', $this->verdict(1, 0.92, frames: [])),
            $this->request(),
        )[0];

        $this->assertSame(VlmBand::Review, $result->band);
        $this->assertSame('no-frame-reference', $result->rejectionReason);
    }

    public function test_all_unstamped_frame_references_still_reach_auto(): void
    {
        // Carousel/thumbnail posts: every surviving keyframe is unstamped,
        // so the verdict's citations are all null entries (validator
        // contract). They are still VALIDATED frame references — an
        // auto-grade confirmed-visible verdict bands AUTO, never
        // 'no-frame-reference' REVIEW.
        $result = $this->mapper()->map(
            $this->set('PRODUCT_CONFIRMED', $this->verdict(1, 0.91, frames: [null])),
            $this->request(),
        )[0];

        $this->assertSame(VlmBand::Auto, $result->band);
        $this->assertNull($result->rejectionReason);
    }

    public function test_mid_band_confidence_lands_review_without_reason(): void
    {
        $result = $this->mapper()->map(
            $this->set('PRODUCT_CONFIRMED', $this->verdict(1, 0.72)),
            $this->request(),
        )[0];

        $this->assertSame(VlmBand::Review, $result->band);
        $this->assertNull($result->rejectionReason);
    }

    public function test_exact_thresholds_are_inclusive(): void
    {
        $atReview = $this->mapper()->map(
            $this->set('PRODUCT_CONFIRMED', $this->verdict(1, 0.60)),
            $this->request(),
        )[0];
        $this->assertSame(VlmBand::Review, $atReview->band);

        $atAuto = $this->mapper()->map(
            $this->set('PRODUCT_CONFIRMED', $this->verdict(1, 0.85)),
            $this->request(),
        )[0];
        $this->assertSame(VlmBand::Auto, $atAuto->band);
    }

    public function test_set_wise_margin_ambiguity_blocks_auto_for_both(): void
    {
        // 0.90 beats 0.85 by only 0.05 < margin 0.10 → NO AUTO is issued
        // for the run; both confirmed-visible candidates land REVIEW.
        $results = $this->mapper()->map(
            $this->set('PRODUCT_CONFIRMED', $this->verdict(1, 0.90), $this->verdict(2, 0.85)),
            $this->request(),
        );

        $this->assertSame(VlmBand::Review, $results[0]->band);
        $this->assertSame('margin-ambiguous', $results[0]->rejectionReason);
        $this->assertSame(VlmBand::Review, $results[1]->band);
        $this->assertSame('margin-ambiguous', $results[1]->rejectionReason);
    }

    public function test_three_clustered_candidates_all_land_review(): void
    {
        $results = $this->mapper()->map(
            $this->set(
                'PRODUCT_CONFIRMED',
                $this->verdict(1, 0.92),
                $this->verdict(2, 0.90),
                $this->verdict(3, 0.86),
            ),
            $this->request(),
        );

        foreach ($results as $result) {
            $this->assertSame(VlmBand::Review, $result->band);
            $this->assertSame('margin-ambiguous', $result->rejectionReason);
        }
    }

    public function test_clear_margin_winner_bands_auto_runner_up_review(): void
    {
        // Input order must not matter: pass the runner-up first.
        $results = $this->mapper()->map(
            $this->set(
                'PRODUCT_CONFIRMED',
                $this->verdict(2, 0.85),
                $this->verdict(1, 0.96),
                $this->verdict(3, 0.70),
            ),
            $this->request(),
        );

        // Ranked best-first: confidence desc.
        $this->assertSame([1, 2, 3], array_map(fn (VlmBandResult $r): int => $r->verdict->productId, $results));
        $this->assertSame(VlmBand::Auto, $results[0]->band);
        // The runner-up has AUTO-grade confidence of its own but loses the
        // margin — REVIEW, never a second AUTO.
        $this->assertSame(VlmBand::Review, $results[1]->band);
        $this->assertSame('margin-ambiguous', $results[1]->rejectionReason);
        // The mid-band third is plain REVIEW.
        $this->assertSame(VlmBand::Review, $results[2]->band);
        $this->assertNull($results[2]->rejectionReason);
    }

    public function test_margin_exactly_at_threshold_is_enough(): void
    {
        // 0.95 - 0.85 = 0.10 ≥ margin 0.10 (float dust neutralized by
        // rounding — the raw subtraction is 0.09999… in IEEE 754).
        $results = $this->mapper()->map(
            $this->set('PRODUCT_CONFIRMED', $this->verdict(1, 0.95), $this->verdict(2, 0.85)),
            $this->request(),
        );

        $this->assertSame(VlmBand::Auto, $results[0]->band);
        $this->assertSame(VlmBand::Review, $results[1]->band);
    }

    public function test_caption_echo_caps_an_otherwise_auto_verdict(): void
    {
        // The caption names the product (case-insensitively) — the §6
        // injection lane: an otherwise-AUTO verdict caps at REVIEW.
        $result = $this->mapper()->map(
            $this->set('PRODUCT_CONFIRMED', $this->verdict(1, 0.93)),
            $this->request(caption: 'Obsessed with my YOU PERFUME right now'),
        )[0];

        $this->assertSame(VlmBand::Review, $result->band);
        $this->assertSame('caption-echo', $result->rejectionReason);
        $this->assertTrue($result->captionEcho);
    }

    public function test_transcript_echo_also_trips_the_guard(): void
    {
        $result = $this->mapper()->map(
            $this->set('PRODUCT_CONFIRMED', $this->verdict(1, 0.93)),
            $this->request(transcript: 'so today I am reviewing the you perfume from glossier'),
        )[0];

        $this->assertSame(VlmBand::Review, $result->band);
        $this->assertSame('caption-echo', $result->rejectionReason);
    }

    public function test_echo_on_a_plain_review_verdict_flags_but_does_not_change_the_reason(): void
    {
        // The cap only demotes otherwise-AUTO verdicts; a mid-band verdict
        // keeps its null reason but still carries the echo fact for the
        // writer's signal trail and sub-project E.
        $result = $this->mapper()->map(
            $this->set('PRODUCT_CONFIRMED', $this->verdict(1, 0.70)),
            $this->request(caption: 'my You Perfume haul'),
        )[0];

        $this->assertSame(VlmBand::Review, $result->band);
        $this->assertNull($result->rejectionReason);
        $this->assertTrue($result->captionEcho);
    }

    public function test_echo_of_a_different_candidate_does_not_cap(): void
    {
        // Caption names P2's product; the P1 verdict stays AUTO — the echo
        // check is per candidate, keyed through candidateByKey.
        $result = $this->mapper()->map(
            $this->set('PRODUCT_CONFIRMED', $this->verdict(1, 0.93)),
            $this->request(caption: 'loving this Cloud Blush shade'),
        )[0];

        $this->assertSame(VlmBand::Auto, $result->band);
        $this->assertFalse($result->captionEcho);
    }

    public function test_inconclusive_run_never_bands_auto_and_never_rejects_positive_claims(): void
    {
        // INCONCLUSIVE is first-class and distinct from PRODUCT_ABSENT
        // (unavailable ≠ false): a confident visible claim under an
        // inconclusive run lands REVIEW, never AUTO, never REJECT.
        $result = $this->mapper()->map(
            $this->set('INCONCLUSIVE', $this->verdict(1, 0.95)),
            $this->request(),
        )[0];

        $this->assertSame(VlmBand::Review, $result->band);
        $this->assertNull($result->rejectionReason);
    }

    public function test_totality_ranking_and_determinism(): void
    {
        $set = $this->set(
            'PRODUCT_CONFIRMED',
            $this->verdict(1, 0.91),
            $this->verdict(2, 0.70),
            $this->verdict(3, 0.95, visible: false, spoken: true),
            $this->verdict(4, 0.20),
        );
        $request = $this->request(products: [
            1 => ['You Perfume', 'Glossier'], 2 => ['Cloud Blush', 'Glossier'],
            3 => ['Boy Brow', 'Glossier'], 4 => ['Balm Dotcom', 'Glossier'],
        ]);

        $results = $this->mapper()->map($set, $request);

        // Totality: exactly one band per schema-valid verdict.
        $this->assertCount(4, $results);
        // Ranked by confidence desc (band-independent), ties lower productId.
        $this->assertSame([3, 1, 2, 4], array_map(fn (VlmBandResult $r): int => $r->verdict->productId, $results));
        $this->assertSame(
            [VlmBand::Review, VlmBand::Auto, VlmBand::Review, VlmBand::Reject],
            array_map(fn (VlmBandResult $r): VlmBand => $r->band, $results),
        );
        // Visible set = {0.91, 0.70}: margin 0.21 clear → the 0.91 is AUTO
        // even though a spoken-only claim outranks it.
        // Determinism: identical inputs ⇒ identical output.
        $this->assertEquals($results, $this->mapper()->map($set, $request));
    }

    public function test_threshold_config_resolution(): void
    {
        config()->set('qds.enrichment.vlm.thresholds', [
            'auto' => 0.70, 'review' => 0.50, 'margin' => 0.10,
        ]);

        $result = $this->mapper()->map(
            $this->set('PRODUCT_CONFIRMED', $this->verdict(1, 0.75)),
            $this->request(),
        )[0];

        $this->assertSame(VlmBand::Auto, $result->band);
    }

    private function mapper(): VlmBandMapper
    {
        return new VlmBandMapper;
    }

    /** @param list<int|null> $frames */
    private function verdict(int $productId, float $confidence, bool $visible = true, bool $spoken = false,
        bool $giftingCue = false, array $frames = [2000], string $rationale = 'Seen on the vanity.'): CandidateVerdict
    {
        return new CandidateVerdict(
            productKey: 'P'.$productId,
            productId: $productId,
            visible: $visible,
            spoken: $spoken,
            giftingCue: $giftingCue,
            confidence: $confidence,
            frameTimestampsMs: $frames,
            rationale: $rationale,
        );
    }

    /** @param array<int, array{0: string, 1: string}> $products id => [label, brand] */
    private function request(string $caption = '', string $transcript = '', array $products = [
        1 => ['You Perfume', 'Glossier'], 2 => ['Cloud Blush', 'Glossier'], 3 => ['Boy Brow', 'Glossier'],
    ]): VlmRequest
    {
        $candidates = [];

        foreach ($products as $id => [$label, $brand]) {
            $candidates[] = new VlmCandidate(
                key: 'P'.$id, productId: $id, label: $label, brand: $brand,
                category: null, aliases: [], cBand: null, cScore: null,
            );
        }

        return new VlmRequest(
            frames: [new VlmFrame(name: 'FRAME_1', timestampMs: 2000, bytes: '', mimeType: 'image/jpeg')],
            candidates: $candidates,
            caption: $caption,
            transcript: $transcript,
            prompt: '',
        );
    }

    private function set(string $outcome, CandidateVerdict ...$verdicts): VerdictSet
    {
        return new VerdictSet(outcome: $outcome, verdicts: array_values($verdicts));
    }
}
