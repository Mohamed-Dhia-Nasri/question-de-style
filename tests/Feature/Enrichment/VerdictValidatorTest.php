<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\VlmVerification\Requests\VlmCandidate;
use App\Platform\Enrichment\VlmVerification\Requests\VlmFrame;
use App\Platform\Enrichment\VlmVerification\Requests\VlmRequest;
use App\Platform\Enrichment\VlmVerification\Verdicts\VerdictValidationResult;
use App\Platform\Enrichment\VlmVerification\Verdicts\VerdictValidator;
use Tests\TestCase;

/**
 * Defense in depth over the enum-grounded schema (spec §6, fail-closed):
 * even though responseSchema constrains decoding, every response is
 * re-checked — exact cover of the candidate set, sent-frame references,
 * confidence range, and the outcome↔verdict consistency rule (a
 * "confirmed" response with no confirming verdict normalizes to
 * INCONCLUSIVE — recorded, never retried). A hard violation is a
 * MALFORMED response that drives the job's bounded corrective retry.
 */
class VerdictValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['qds.enrichment.vlm.thresholds' => ['auto' => 0.85, 'review' => 0.60, 'margin' => 0.10]]);
    }

    private function request(): VlmRequest
    {
        return new VlmRequest(
            frames: [
                new VlmFrame('FRAME_1', 1500, 'frame-one-bytes', 'image/jpeg'),
                new VlmFrame('FRAME_2', 8000, 'frame-two-bytes', 'image/jpeg'),
                new VlmFrame('FRAME_3', null, 'frame-three-bytes', 'image/jpeg'),
            ],
            candidates: [
                new VlmCandidate('P123', 123, 'Aurora Glow Serum', 'Lumen Skincare', 'BEAUTY', ['Glow Serum'], 'review', 0.61),
                new VlmCandidate('P456', 456, 'Nexon Labs Headset', 'Nexon Labs', 'TECH', [], null, null),
            ],
            caption: 'Unboxing my favorites',
            transcript: '',
            prompt: 'PROMPT-TEXT',
        );
    }

    /** @return array<string, mixed> */
    private function verdict(string $key, array $overrides = []): array
    {
        return array_merge([
            'product_key' => $key,
            'visible' => false,
            'spoken' => false,
            'gifting_cue' => false,
            'confidence' => 0.20,
            'frame_names' => [],
            'rationale' => 'Not seen.',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function confirmedJson(): array
    {
        return [
            'outcome' => 'PRODUCT_CONFIRMED',
            'verdicts' => [
                $this->verdict('P123', ['visible' => true, 'confidence' => 0.91, 'frame_names' => ['FRAME_2', 'FRAME_1'], 'rationale' => 'Serum bottle on the desk.']),
                $this->verdict('P456'),
            ],
            'overall_rationale' => 'One clear match.',
        ];
    }

    private function validate(array $json): VerdictValidationResult
    {
        return app(VerdictValidator::class)->validate($json, $this->request());
    }

    public function test_a_valid_response_maps_to_an_ordered_verdict_set(): void
    {
        // Response order reversed on purpose — the set is normalized to
        // the request's candidate (rank) order.
        $json = $this->confirmedJson();
        $json['verdicts'] = array_reverse($json['verdicts']);

        $result = $this->validate($json);

        $this->assertNull($result->malformedReason);
        $this->assertFalse($result->normalizedInconclusive);
        $set = $result->verdicts;
        $this->assertNotNull($set);
        $this->assertSame('PRODUCT_CONFIRMED', $set->outcome);
        $this->assertSame(['P123', 'P456'], array_map(fn ($v): string => $v->productKey, $set->verdicts));
        $this->assertSame([123, 456], array_map(fn ($v): int => $v->productId, $set->verdicts));

        $first = $set->verdicts[0];
        $this->assertTrue($first->visible);
        $this->assertFalse($first->spoken);
        $this->assertFalse($first->giftingCue);
        $this->assertSame(0.91, $first->confidence);
        // Frame references map to validated timestamps, ascending.
        $this->assertSame([1500, 8000], $first->frameTimestampsMs);
        $this->assertSame('Serum bottle on the desk.', $first->rationale);
    }

    public function test_confidence_is_rounded_to_four_decimals(): void
    {
        $json = $this->confirmedJson();
        $json['verdicts'][0]['confidence'] = 0.912345678;

        $this->assertSame(0.9123, $this->validate($json)->verdicts?->verdicts[0]->confidence);
    }

    public function test_missing_or_invalid_outcome_is_malformed(): void
    {
        $this->assertSame('missing-or-invalid-outcome', $this->validate([])->malformedReason);
        $this->assertSame('missing-or-invalid-outcome', $this->validate(['outcome' => 'MAYBE', 'verdicts' => []])->malformedReason);
        $this->assertNull($this->validate(['outcome' => 'MAYBE', 'verdicts' => []])->verdicts);
    }

    public function test_missing_verdicts_key_is_malformed(): void
    {
        $this->assertSame('missing-or-invalid-verdicts', $this->validate(['outcome' => 'INCONCLUSIVE'])->malformedReason);
    }

    public function test_a_missing_candidate_breaks_the_exact_cover(): void
    {
        $json = $this->confirmedJson();
        unset($json['verdicts'][1]);
        $json['verdicts'] = array_values($json['verdicts']);

        $this->assertSame('verdict-count-mismatch:1-of-2', $this->validate($json)->malformedReason);
    }

    public function test_an_extra_verdict_breaks_the_exact_cover(): void
    {
        $json = $this->confirmedJson();
        $json['verdicts'][] = $this->verdict('P123');

        $this->assertSame('verdict-count-mismatch:3-of-2', $this->validate($json)->malformedReason);
    }

    public function test_a_duplicated_key_breaks_the_exact_cover(): void
    {
        $json = $this->confirmedJson();
        $json['verdicts'][1]['product_key'] = 'P123';

        $this->assertSame('duplicate-product-key:P123', $this->validate($json)->malformedReason);
    }

    public function test_an_out_of_catalog_key_is_malformed(): void
    {
        $json = $this->confirmedJson();
        $json['verdicts'][1]['product_key'] = 'P999';

        $this->assertSame('out-of-catalog-product-key:P999', $this->validate($json)->malformedReason);
    }

    public function test_an_unknown_frame_name_is_malformed(): void
    {
        $json = $this->confirmedJson();
        $json['verdicts'][0]['frame_names'] = ['FRAME_1', 'FRAME_9'];

        $this->assertSame('unknown-frame-name:FRAME_9', $this->validate($json)->malformedReason);
    }

    public function test_unstamped_frame_references_validate_but_add_no_timestamp(): void
    {
        $json = $this->confirmedJson();
        $json['verdicts'][0]['frame_names'] = ['FRAME_3', 'FRAME_1'];

        $result = $this->validate($json);

        $this->assertNull($result->malformedReason);
        $this->assertSame([1500], $result->verdicts?->verdicts[0]->frameTimestampsMs);
    }

    public function test_duplicate_frame_references_collapse(): void
    {
        $json = $this->confirmedJson();
        $json['verdicts'][0]['frame_names'] = ['FRAME_1', 'FRAME_1', 'FRAME_2'];

        $this->assertSame([1500, 8000], $this->validate($json)->verdicts?->verdicts[0]->frameTimestampsMs);
    }

    public function test_out_of_range_or_non_numeric_confidence_is_malformed(): void
    {
        $tooHigh = $this->confirmedJson();
        $tooHigh['verdicts'][0]['confidence'] = 1.2;
        $this->assertSame('confidence-out-of-range:P123', $this->validate($tooHigh)->malformedReason);

        $negative = $this->confirmedJson();
        $negative['verdicts'][1]['confidence'] = -0.1;
        $this->assertSame('confidence-out-of-range:P456', $this->validate($negative)->malformedReason);

        $text = $this->confirmedJson();
        $text['verdicts'][0]['confidence'] = 'high';
        $this->assertSame('confidence-out-of-range:P123', $this->validate($text)->malformedReason);
    }

    public function test_non_boolean_flags_and_missing_rationale_are_malformed(): void
    {
        $badFlag = $this->confirmedJson();
        $badFlag['verdicts'][0]['visible'] = 'yes';
        $this->assertSame('invalid-flag:visible:P123', $this->validate($badFlag)->malformedReason);

        $badCue = $this->confirmedJson();
        unset($badCue['verdicts'][1]['gifting_cue']);
        $this->assertSame('invalid-flag:gifting_cue:P456', $this->validate($badCue)->malformedReason);

        $noRationale = $this->confirmedJson();
        unset($noRationale['verdicts'][0]['rationale']);
        $this->assertSame('missing-rationale:P123', $this->validate($noRationale)->malformedReason);
    }

    public function test_confirmed_without_a_confirming_verdict_normalizes_to_inconclusive(): void
    {
        // All-negative verdicts under a PRODUCT_CONFIRMED outcome (§6):
        // normalized, recorded, NOT retried.
        $allNegative = [
            'outcome' => 'PRODUCT_CONFIRMED',
            'verdicts' => [$this->verdict('P123'), $this->verdict('P456')],
        ];

        $result = $this->validate($allNegative);

        $this->assertNull($result->malformedReason);
        $this->assertTrue($result->normalizedInconclusive);
        $this->assertSame('INCONCLUSIVE', $result->verdicts?->outcome);
    }

    public function test_confirmed_below_the_review_threshold_normalizes_to_inconclusive(): void
    {
        $json = $this->confirmedJson();
        $json['verdicts'][0]['confidence'] = 0.30; // visible, but < review (0.60)

        $result = $this->validate($json);

        $this->assertTrue($result->normalizedInconclusive);
        $this->assertSame('INCONCLUSIVE', $result->verdicts?->outcome);
    }

    public function test_a_spoken_only_confirmation_at_review_confidence_is_not_normalized(): void
    {
        $json = $this->confirmedJson();
        $json['verdicts'][0]['visible'] = false;
        $json['verdicts'][0]['spoken'] = true;
        $json['verdicts'][0]['confidence'] = 0.60;
        $json['verdicts'][0]['frame_names'] = [];

        $result = $this->validate($json);

        $this->assertFalse($result->normalizedInconclusive);
        $this->assertSame('PRODUCT_CONFIRMED', $result->verdicts?->outcome);
    }

    public function test_a_schema_conforming_refusal_is_a_legitimate_inconclusive(): void
    {
        $refusal = [
            'outcome' => 'INCONCLUSIVE',
            'verdicts' => [
                $this->verdict('P123', ['rationale' => 'Frames too blurry to judge.']),
                $this->verdict('P456', ['rationale' => 'Frames too blurry to judge.']),
            ],
        ];

        $result = $this->validate($refusal);

        $this->assertNull($result->malformedReason);
        $this->assertFalse($result->normalizedInconclusive);
        $this->assertSame('INCONCLUSIVE', $result->verdicts?->outcome);
    }

    public function test_an_unlisted_product_in_rationale_text_is_inert(): void
    {
        // Fabrication inertness (§6): rationale text cannot mint products —
        // the verdict set carries ONLY catalog product ids.
        $json = $this->confirmedJson();
        $json['verdicts'][0]['rationale'] = 'This is clearly the unreleased MegaCorp UltraWidget 9000.';

        $result = $this->validate($json);

        $this->assertNull($result->malformedReason);
        $this->assertSame([123, 456], array_map(fn ($v): int => $v->productId, $result->verdicts?->verdicts ?? []));
    }

    public function test_validation_is_deterministic(): void
    {
        $json = $this->confirmedJson();

        $first = $this->validate($json);
        $second = $this->validate($json);

        $this->assertEquals($first, $second);
    }
}
