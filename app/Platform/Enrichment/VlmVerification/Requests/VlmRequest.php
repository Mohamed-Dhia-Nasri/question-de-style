<?php

namespace App\Platform\Enrichment\VlmVerification\Requests;

/**
 * One fully-assembled generateContent request for a VLM verification
 * (spec §5/§6): the prompt text part, the prepared frames as inlineData
 * parts (each pinned to the configured media_resolution), the closed
 * candidate catalog, and the per-request enum-grounded responseSchema
 * whose exact-cover contract (minItems = maxItems = candidate count)
 * makes "one verdict per candidate" a DECODE-level guarantee.
 *
 * textualPayload() is the AiPayloadGuard view: the full request minus the
 * base64 frame parts — the base64 alphabet contains no '@', whitespace,
 * or query separators, so it cannot trip the guard's patterns, and ~MBs
 * of image data are never regex-scanned (spec §5).
 *
 * Field-casing note (spec §18): `media_resolution` (per part) and
 * `thinking_level` (generationConfig) are the spec-pinned Gemini 3 knobs;
 * their exact REST casing is re-verified by the go-live smoke task.
 */
final readonly class VlmRequest
{
    private const OUTCOMES = ['PRODUCT_CONFIRMED', 'PRODUCT_ABSENT', 'INCONCLUSIVE'];

    public function __construct(
        /** @var list<VlmFrame> */
        public array $frames,
        /** @var list<VlmCandidate> */
        public array $candidates,
        public string $caption,
        public string $transcript,
        public string $prompt,
    ) {}

    /** @return array<string, mixed> full generateContent body incl. inlineData parts */
    public function payload(): array
    {
        $parts = [['text' => $this->prompt]];

        // media_resolution is OMITTED when the config is empty. The go-live smoke
        // (2026-07-21) confirmed the live API rejects the value MEDIA_RESOLUTION_MEDIUM
        // per Part with HTTP 400 (Part.MediaResolution). Default is now empty so the
        // model uses its own resolution; re-enable with a value verified against
        // current official docs (spec §18 watch item — a cost optimization only).
        $resolution = (string) config('qds.enrichment.vlm.media_resolution');

        foreach ($this->frames as $frame) {
            $part = ['inlineData' => ['mimeType' => $frame->mimeType, 'data' => base64_encode($frame->bytes)]];

            if ($resolution !== '') {
                $part['media_resolution'] = $resolution;
            }

            $parts[] = $part;
        }

        return [
            // role is REQUIRED by generateContent ("Please use a valid role:
            // user, model." — go-live smoke, 2026-07-21).
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => $this->generationConfig(),
        ];
    }

    /** @return array<string, mixed> payload() minus inlineData — the AiPayloadGuard view */
    public function textualPayload(): array
    {
        return [
            'contents' => [['role' => 'user', 'parts' => [['text' => $this->prompt]]]],
            'generationConfig' => $this->generationConfig(),
        ];
    }

    /**
     * Built per request with the candidate keys baked into string enums —
     * only fields from the verified supported subset (spec §2b.3):
     * unsupported keywords are silently ignored by the API, so none are
     * used.
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        $candidateKeys = array_map(fn (VlmCandidate $candidate): string => $candidate->key, $this->candidates);
        $frameNames = array_map(fn (VlmFrame $frame): string => $frame->name, $this->frames);
        $candidateCount = count($this->candidates);

        return [
            'type' => 'object',
            'propertyOrdering' => ['outcome', 'verdicts', 'overall_rationale'],
            'required' => ['outcome', 'verdicts'],
            'properties' => [
                'outcome' => ['type' => 'string', 'enum' => self::OUTCOMES],
                'verdicts' => [
                    'type' => 'array',
                    // Exact-cover contract (spec §6): exactly one verdict
                    // per candidate, enforced at the decoding level.
                    'minItems' => $candidateCount,
                    'maxItems' => $candidateCount,
                    'items' => [
                        'type' => 'object',
                        'propertyOrdering' => ['product_key', 'visible', 'spoken', 'gifting_cue', 'confidence', 'frame_names', 'rationale'],
                        'required' => ['product_key', 'visible', 'spoken', 'gifting_cue', 'confidence', 'rationale'],
                        'properties' => [
                            'product_key' => ['type' => 'string', 'enum' => $candidateKeys],
                            'visible' => ['type' => 'boolean'],
                            'spoken' => ['type' => 'boolean'],
                            'gifting_cue' => ['type' => 'boolean'],
                            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                            'frame_names' => [
                                'type' => 'array',
                                'maxItems' => count($frameNames),
                                'items' => ['type' => 'string', 'enum' => $frameNames],
                            ],
                            'rationale' => ['type' => 'string'],
                        ],
                    ],
                ],
                'overall_rationale' => ['type' => 'string'],
            ],
        ];
    }

    public function frameTimestamp(string $frameName): ?int
    {
        foreach ($this->frames as $frame) {
            if ($frame->name === $frameName) {
                return $frame->timestampMs;
            }
        }

        return null;
    }

    public function candidateByKey(string $key): ?VlmCandidate
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->key === $key) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function generationConfig(): array
    {
        $config = [
            'responseMimeType' => 'application/json',
            'responseSchema' => $this->schema(),
            'temperature' => 0,
            'maxOutputTokens' => (int) config('qds.enrichment.vlm.max_output_tokens'),
        ];

        // thinking_level is OMITTED when the config is empty. The go-live smoke
        // (2026-07-21) confirmed the live API rejects `thinking_level` in
        // generation_config with HTTP 400 ("Unknown name … Cannot find field").
        // Default is now empty so the model's own thinking applies; re-enable with
        // the field name/shape verified against current official docs (spec §18).
        $thinking = (string) config('qds.enrichment.vlm.thinking_level');

        if ($thinking !== '') {
            $config['thinking_level'] = $thinking;
        }

        return $config;
    }
}
