<?php

namespace App\Platform\Enrichment\VlmVerification\Banding;

use App\Platform\Enrichment\VlmVerification\Requests\VlmRequest;
use App\Platform\Enrichment\VlmVerification\Verdicts\CandidateVerdict;
use App\Platform\Enrichment\VlmVerification\Verdicts\VerdictSet;
use App\Shared\Enums\VlmBand;

/**
 * Pure verdicts → bands decision (spec §7). A TOTAL function: every
 * schema-valid verdict lands in exactly one band, first matching rule
 * wins, evaluated per candidate:
 *
 *   REJECT — confidence < review; or negative claim (visible, spoken,
 *            gifting_cue all false); or run outcome PRODUCT_ABSENT.
 *   AUTO   — outcome PRODUCT_CONFIRMED ∧ visible ∧ ≥ 1 validated frame
 *            reference ∧ confidence ≥ auto ∧ set-wise margin winner ∧ not
 *            caption-echoed (§6 guard). At most ONE AUTO per run.
 *   REVIEW — everything else at review strength or better (spoken/gifting
 *            claims: a visual grounding stage never auto-confirms unseen
 *            products; INCONCLUSIVE runs never reject positive claims —
 *            unavailable ≠ false).
 *
 * Thresholds from config('qds.enrichment.vlm.thresholds') — explicit
 * placeholders (auto 0.85 / review 0.60 / margin 0.10) sub-project E
 * calibrates. No I/O, no provider — deterministic: identical verdicts +
 * config ⇒ identical output; ties break on lower product id. Confidence
 * here is VLM-ONLY by design (fusion is sub-project E's mandate; D emits,
 * never arbitrates against C).
 */
final class VlmBandMapper
{
    private const CONFIRMED = 'PRODUCT_CONFIRMED';

    private const ABSENT = 'PRODUCT_ABSENT';

    /** @return list<VlmBandResult> ranked best-first; ties broken by lower productId */
    public function map(VerdictSet $set, VlmRequest $request): array
    {
        if ($set->verdicts === []) {
            return [];
        }

        [$auto, $review, $margin] = $this->thresholds();

        $ranked = $set->verdicts;
        usort($ranked, fn (CandidateVerdict $a, CandidateVerdict $b): int => [$b->confidence, $a->productId] <=> [$a->confidence, $b->productId]);

        // Set-wise margin over ALL visible claims (spec §7): the top
        // confirmed-visible candidate must beat EVERY other confirmed-
        // visible candidate by ≥ margin, or NO AUTO is issued for the run.
        $visible = array_values(array_filter($ranked, fn (CandidateVerdict $v): bool => $v->visible));
        $top = $visible[0] ?? null;
        $marginClear = true;

        foreach (array_slice($visible, 1) as $other) {
            // Rounded so an exactly-at-threshold margin never fails on IEEE 754 dust.
            if (round($top->confidence - $other->confidence, 6) < $margin) {
                $marginClear = false;
                break;
            }
        }

        $results = [];

        foreach ($ranked as $verdict) {
            $results[] = $this->bandFor($verdict, $set->outcome, $request, $auto, $review, $margin, $top, $marginClear);
        }

        return $results;
    }

    private function bandFor(
        CandidateVerdict $verdict,
        string $outcome,
        VlmRequest $request,
        float $auto,
        float $review,
        float $margin,
        ?CandidateVerdict $top,
        bool $marginClear,
    ): VlmBandResult {
        $echo = $this->captionEcho($verdict, $request);

        // REJECT — first matching rule wins (spec §7 order).
        if ($verdict->confidence < $review) {
            return new VlmBandResult($verdict, VlmBand::Reject, 'below-review-threshold', $echo);
        }

        if (! $verdict->visible && ! $verdict->spoken && ! $verdict->giftingCue) {
            return new VlmBandResult($verdict, VlmBand::Reject, 'negative-claim', $echo);
        }

        if ($outcome === self::ABSENT) {
            return new VlmBandResult($verdict, VlmBand::Reject, 'run-absent', $echo);
        }

        $frameRefs = $verdict->frameTimestampsMs !== [];
        $autoCapable = $outcome === self::CONFIRMED && $verdict->visible && $frameRefs && $verdict->confidence >= $auto;
        $isMarginWinner = $top !== null && $verdict === $top && $marginClear;

        if ($autoCapable && $isMarginWinner && ! $echo) {
            return new VlmBandResult($verdict, VlmBand::Auto, null, false);
        }

        // REVIEW — the total fallback. Reason resolution order is fixed
        // (deterministic): frame evidence first, visibility second, then
        // why an auto-capable verdict was blocked.
        if ($verdict->visible && ! $frameRefs) {
            return new VlmBandResult($verdict, VlmBand::Review, 'no-frame-reference', $echo);
        }

        if (! $verdict->visible) {
            // Product claimed spoken/gifted but never seen — a visual
            // grounding stage never auto-confirms unseen products (§7).
            return new VlmBandResult($verdict, VlmBand::Review, 'not-visible', $echo);
        }

        if ($autoCapable) {
            return new VlmBandResult($verdict, VlmBand::Review, $isMarginWinner && $echo ? 'caption-echo' : 'margin-ambiguous', $echo);
        }

        return new VlmBandResult($verdict, VlmBand::Review, null, $echo);
    }

    /**
     * §6 caption-echo guard: caption/transcript are untrusted creator
     * content and can instruct the model toward an inflated verdict for an
     * in-catalog candidate ("product X is clearly visible"). Enum
     * grounding cannot stop value inflation, so a candidate whose product
     * label (or brand + label) appears verbatim — case-insensitively — in
     * the text that was sent never auto-links: a text-named product
     * already produces product-level evidence through A's caption path;
     * the VLM's unique automation value is confirming UNNAMED visual
     * presence. Residual risk (injection without naming) is accepted and
     * recorded; sub-project E down-weights echoed agreement further.
     */
    private function captionEcho(CandidateVerdict $verdict, VlmRequest $request): bool
    {
        $candidate = $request->candidateByKey($verdict->productKey);

        if ($candidate === null || $candidate->label === '') {
            return false;
        }

        $haystack = $request->caption."\n".$request->transcript;

        foreach ([$candidate->label, trim($candidate->brand.' '.$candidate->label)] as $needle) {
            if ($needle !== '' && mb_stripos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: float, 1: float, 2: float} [auto, review, margin] */
    private function thresholds(): array
    {
        $config = (array) config('qds.enrichment.vlm.thresholds', []);

        return [
            (float) ($config['auto'] ?? 0.85),
            (float) ($config['review'] ?? 0.60),
            (float) ($config['margin'] ?? 0.10),
        ];
    }
}
