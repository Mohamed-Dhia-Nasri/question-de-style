<?php

namespace Tests\Feature\Enrichment;

use App\Platform\Enrichment\VlmVerification\Requests\VlmCandidate;
use App\Platform\Enrichment\VlmVerification\Requests\VlmFrame;
use App\Platform\Enrichment\VlmVerification\Requests\VlmRequest;
use Tests\TestCase;

/**
 * The generateContent request envelope (spec §5/§6): contents[0] carries
 * the REQUIRED `role: user` (the live API 400s without it — go-live
 * smoke, 2026-07-21), then the prompt text part + one inlineData part
 * per frame, generationConfig with the per-request enum-grounded
 * responseSchema, and the textual view (no base64) that AiPayloadGuard
 * scans. The media_resolution (per part) and thinking_level
 * (generationConfig) knobs are OMITTED while their configs are empty —
 * the smoke saw HTTP 400 on both — and included only when set. The
 * exact-cover contract — minItems = maxItems = the candidate count —
 * makes "one verdict per candidate" a decode-level guarantee.
 */
class VlmRequestTest extends TestCase
{
    private function request(): VlmRequest
    {
        return new VlmRequest(
            frames: [
                new VlmFrame('FRAME_1', 2000, 'frame-one-bytes', 'image/jpeg'),
                new VlmFrame('FRAME_2', 8000, 'frame-two-bytes', 'image/png'),
                new VlmFrame('FRAME_3', null, 'frame-three-bytes', 'image/jpeg'),
            ],
            candidates: [
                new VlmCandidate('P123', 123, 'Aurora Glow Serum', 'Lumen Skincare', 'BEAUTY', ['Glow Serum'], 'review', 0.61),
                new VlmCandidate('P456', 456, 'Nexon Labs Headset', 'Nexon Labs', 'TECH', [], null, null),
            ],
            caption: 'Unboxing my favorites',
            transcript: 'so excited to try this serum',
            prompt: 'PROMPT-TEXT',
        );
    }

    public function test_payload_carries_the_prompt_then_one_inline_data_part_per_frame(): void
    {
        $payload = $this->request()->payload();

        $parts = $payload['contents'][0]['parts'];
        $this->assertCount(4, $parts);
        $this->assertSame(['text' => 'PROMPT-TEXT'], $parts[0]);
        // No media_resolution with the shipped empty default — the live API
        // rejected MEDIA_RESOLUTION_MEDIUM with HTTP 400 (go-live smoke,
        // 2026-07-21).
        $this->assertSame([
            'inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode('frame-one-bytes')],
        ], $parts[1]);
        $this->assertSame(base64_encode('frame-two-bytes'), $parts[2]['inlineData']['data']);
        $this->assertSame(base64_encode('frame-three-bytes'), $parts[3]['inlineData']['data']);
    }

    public function test_generation_config_pins_json_schema_and_temperature_and_omits_thinking_level_by_default(): void
    {
        config(['qds.enrichment.vlm.max_output_tokens' => 1024]);
        $request = $this->request();

        $generationConfig = $request->payload()['generationConfig'];

        $this->assertSame('application/json', $generationConfig['responseMimeType']);
        $this->assertSame($request->schema(), $generationConfig['responseSchema']);
        $this->assertSame(0, $generationConfig['temperature']);
        $this->assertSame(1024, $generationConfig['maxOutputTokens']);
        // OMITTED with the shipped empty default — the live API rejected the
        // field itself with HTTP 400 ("Unknown name" — go-live smoke,
        // 2026-07-21).
        $this->assertArrayNotHasKey('thinking_level', $generationConfig);
    }

    public function test_both_payload_views_stamp_the_required_user_role_on_contents(): void
    {
        $request = $this->request();

        // generateContent REQUIRES the role ("Please use a valid role:
        // user, model." — live HTTP 400, go-live smoke 2026-07-21).
        $this->assertSame('user', $request->payload()['contents'][0]['role']);
        $this->assertSame('user', $request->textualPayload()['contents'][0]['role']);
    }

    public function test_an_empty_media_resolution_config_omits_the_key_from_every_part(): void
    {
        config(['qds.enrichment.vlm.media_resolution' => '']);

        foreach ($this->request()->payload()['contents'][0]['parts'] as $part) {
            $this->assertArrayNotHasKey('media_resolution', $part);
        }
    }

    public function test_a_configured_media_resolution_is_pinned_on_every_image_part(): void
    {
        // The re-enable path for the cost knob (spec §2b.4: MEDIUM = 560
        // tokens/frame) — every frame part carries it, the text part never.
        config(['qds.enrichment.vlm.media_resolution' => 'MEDIA_RESOLUTION_MEDIUM']);

        $parts = $this->request()->payload()['contents'][0]['parts'];

        $this->assertArrayNotHasKey('media_resolution', $parts[0]);
        $this->assertSame('MEDIA_RESOLUTION_MEDIUM', $parts[1]['media_resolution']);
        $this->assertSame('MEDIA_RESOLUTION_MEDIUM', $parts[2]['media_resolution']);
        $this->assertSame('MEDIA_RESOLUTION_MEDIUM', $parts[3]['media_resolution']);
    }

    public function test_an_empty_thinking_level_config_omits_the_key_from_generation_config(): void
    {
        config(['qds.enrichment.vlm.thinking_level' => '']);
        $request = $this->request();

        $this->assertArrayNotHasKey('thinking_level', $request->payload()['generationConfig']);
        $this->assertArrayNotHasKey('thinking_level', $request->textualPayload()['generationConfig']);
    }

    public function test_a_configured_thinking_level_is_included_in_generation_config(): void
    {
        // The re-enable path for the cost knob (spec §2b.5: thinking tokens
        // bill as output) — present in both payload views.
        config(['qds.enrichment.vlm.thinking_level' => 'LOW']);
        $request = $this->request();

        $this->assertSame('LOW', $request->payload()['generationConfig']['thinking_level']);
        $this->assertSame('LOW', $request->textualPayload()['generationConfig']['thinking_level']);
    }

    public function test_the_textual_payload_is_the_payload_without_the_inline_frame_parts(): void
    {
        $request = $this->request();

        $textual = $request->textualPayload();

        $this->assertSame([['text' => 'PROMPT-TEXT']], $textual['contents'][0]['parts']);
        $this->assertSame($request->payload()['generationConfig'], $textual['generationConfig']);
        $this->assertStringNotContainsString(base64_encode('frame-one-bytes'), (string) json_encode($textual));
    }

    public function test_the_schema_grounds_product_keys_and_frame_names_as_request_enums(): void
    {
        $schema = $this->request()->schema();

        $verdictItems = $schema['properties']['verdicts']['items'];
        $this->assertSame(['PRODUCT_CONFIRMED', 'PRODUCT_ABSENT', 'INCONCLUSIVE'], $schema['properties']['outcome']['enum']);
        $this->assertSame(['P123', 'P456'], $verdictItems['properties']['product_key']['enum']);
        $this->assertSame(['FRAME_1', 'FRAME_2', 'FRAME_3'], $verdictItems['properties']['frame_names']['items']['enum']);
        $this->assertSame(['outcome', 'verdicts', 'overall_rationale'], $schema['propertyOrdering']);
        $this->assertSame(['outcome', 'verdicts'], $schema['required']);
        $this->assertSame(
            ['product_key', 'visible', 'spoken', 'gifting_cue', 'confidence', 'frame_names', 'rationale'],
            $verdictItems['propertyOrdering'],
        );
        $this->assertSame(
            ['product_key', 'visible', 'spoken', 'gifting_cue', 'confidence', 'rationale'],
            $verdictItems['required'],
        );
        $this->assertSame(['type' => 'number', 'minimum' => 0, 'maximum' => 1], $verdictItems['properties']['confidence']);
    }

    public function test_the_exact_cover_contract_sets_min_and_max_items_to_the_candidate_count(): void
    {
        $verdicts = $this->request()->schema()['properties']['verdicts'];

        $this->assertSame(2, $verdicts['minItems']);
        $this->assertSame(2, $verdicts['maxItems']);
        // frame_names can never cite more frames than were sent.
        $this->assertSame(3, $verdicts['items']['properties']['frame_names']['maxItems']);
    }

    public function test_the_schema_avoids_keywords_outside_the_verified_supported_subset(): void
    {
        $encoded = (string) json_encode($this->request()->schema());

        // Outside the verified subset (spec §2b.3) these are SILENTLY
        // IGNORED by the API — relying on them would be a fake constraint.
        $this->assertStringNotContainsString('additionalProperties', $encoded);
        $this->assertStringNotContainsString('uniqueItems', $encoded);
        $this->assertStringNotContainsString('pattern', $encoded);
    }

    public function test_frame_timestamp_and_candidate_lookups(): void
    {
        $request = $this->request();

        $this->assertSame(2000, $request->frameTimestamp('FRAME_1'));
        $this->assertNull($request->frameTimestamp('FRAME_3'));   // sent, unstamped
        $this->assertNull($request->frameTimestamp('FRAME_9'));   // never sent
        $this->assertSame(123, $request->candidateByKey('P123')?->productId);
        $this->assertSame(['Glow Serum'], $request->candidateByKey('P123')?->aliases);
        $this->assertNull($request->candidateByKey('P999'));
    }
}
