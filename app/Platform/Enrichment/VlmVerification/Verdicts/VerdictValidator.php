<?php

namespace App\Platform\Enrichment\VlmVerification\Verdicts;

use App\Platform\Enrichment\VlmVerification\Requests\VlmFrame;
use App\Platform\Enrichment\VlmVerification\Requests\VlmRequest;

/**
 * Defense in depth over the enum-grounded responseSchema (spec §6,
 * fail-closed): re-checks — even though the schema constrains decoding —
 * that the JSON has the required shape, the verdict set is an EXACT COVER
 * of the candidate set (every product_key exactly once; missing,
 * duplicated, or out-of-catalog keys are malformed), every frame_name was
 * actually sent, confidence ∈ [0,1], and outcome↔verdict consistency
 * (PRODUCT_CONFIRMED requires ≥ 1 verdict with visible∨spoken at
 * confidence ≥ the review threshold — otherwise the outcome is NORMALIZED
 * to INCONCLUSIVE, recorded, never retried).
 *
 * Malformed reasons are deterministic strings ≤ 100 chars (they land in
 * vlm_verification_runs.rejection_reason varchar(100)). A verdict can
 * never fabricate a product: productId comes from the request's catalog,
 * and an unlisted product mentioned in rationale text is inert by design.
 */
final class VerdictValidator
{
    private const OUTCOMES = ['PRODUCT_CONFIRMED', 'PRODUCT_ABSENT', 'INCONCLUSIVE'];

    public function validate(array $json, VlmRequest $request): VerdictValidationResult
    {
        $outcome = $json['outcome'] ?? null;

        if (! is_string($outcome) || ! in_array($outcome, self::OUTCOMES, true)) {
            return VerdictValidationResult::malformed('missing-or-invalid-outcome');
        }

        $rawVerdicts = $json['verdicts'] ?? null;

        if (! is_array($rawVerdicts) || ! array_is_list($rawVerdicts)) {
            return VerdictValidationResult::malformed('missing-or-invalid-verdicts');
        }

        $expected = count($request->candidates);

        if (count($rawVerdicts) !== $expected) {
            return VerdictValidationResult::malformed(
                sprintf('verdict-count-mismatch:%d-of-%d', count($rawVerdicts), $expected),
            );
        }

        $sentFrameNames = array_map(fn (VlmFrame $frame): string => $frame->name, $request->frames);
        $byKey = [];

        foreach ($rawVerdicts as $raw) {
            if (! is_array($raw)) {
                return VerdictValidationResult::malformed('verdict-not-an-object');
            }

            $key = $raw['product_key'] ?? null;

            if (! is_string($key) || $key === '') {
                return VerdictValidationResult::malformed('missing-product-key');
            }

            $candidate = $request->candidateByKey($key);

            if ($candidate === null) {
                return VerdictValidationResult::malformed("out-of-catalog-product-key:{$key}");
            }

            if (isset($byKey[$key])) {
                return VerdictValidationResult::malformed("duplicate-product-key:{$key}");
            }

            foreach (['visible', 'spoken', 'gifting_cue'] as $flag) {
                if (! is_bool($raw[$flag] ?? null)) {
                    return VerdictValidationResult::malformed("invalid-flag:{$flag}:{$key}");
                }
            }

            $confidence = $raw['confidence'] ?? null;

            if (! is_numeric($confidence) || (float) $confidence < 0.0 || (float) $confidence > 1.0) {
                return VerdictValidationResult::malformed("confidence-out-of-range:{$key}");
            }

            $rationale = $raw['rationale'] ?? null;

            if (! is_string($rationale)) {
                return VerdictValidationResult::malformed("missing-rationale:{$key}");
            }

            $frameNames = $raw['frame_names'] ?? [];

            if (! is_array($frameNames) || ! array_is_list($frameNames)) {
                return VerdictValidationResult::malformed("invalid-frame-names:{$key}");
            }

            $timestamps = [];
            $seenNames = [];

            foreach ($frameNames as $name) {
                if (! is_string($name)) {
                    return VerdictValidationResult::malformed("invalid-frame-names:{$key}");
                }

                if (! in_array($name, $sentFrameNames, true)) {
                    return VerdictValidationResult::malformed("unknown-frame-name:{$name}");
                }

                if (isset($seenNames[$name])) {
                    continue; // duplicate references collapse — no double evidence
                }

                $seenNames[$name] = true;
                $timestamp = $request->frameTimestamp($name);

                if ($timestamp !== null) {
                    $timestamps[] = $timestamp;
                }
            }

            sort($timestamps);

            $byKey[$key] = new CandidateVerdict(
                productKey: $key,
                productId: $candidate->productId,
                visible: $raw['visible'],
                spoken: $raw['spoken'],
                giftingCue: $raw['gifting_cue'],
                confidence: round((float) $confidence, 4),
                frameTimestampsMs: $timestamps,
                rationale: $rationale,
            );
        }

        // Exact cover is now proven: the count equals the candidate count,
        // every key is unique and in-catalog — so every candidate is
        // covered exactly once (a missing candidate would force a
        // duplicate or unknown key, both already rejected).

        // Normalize to the request's candidate (rank) order — deterministic
        // for banding and rank persistence.
        $ordered = [];

        foreach ($request->candidates as $candidate) {
            $ordered[] = $byKey[$candidate->key];
        }

        $normalized = false;

        if ($outcome === 'PRODUCT_CONFIRMED' && ! $this->hasConfirmingVerdict($ordered)) {
            // §6 outcome↔verdict consistency: a "confirmed" response with
            // no confirming verdict is INCONCLUSIVE — recorded (signal
            // vlm-outcome-normalized), never retried.
            $outcome = 'INCONCLUSIVE';
            $normalized = true;
        }

        return VerdictValidationResult::valid(new VerdictSet($outcome, $ordered), $normalized);
    }

    /** @param list<CandidateVerdict> $verdicts */
    private function hasConfirmingVerdict(array $verdicts): bool
    {
        $review = (float) config('qds.enrichment.vlm.thresholds.review');

        foreach ($verdicts as $verdict) {
            if (($verdict->visible || $verdict->spoken) && $verdict->confidence >= $review) {
                return true;
            }
        }

        return false;
    }
}
