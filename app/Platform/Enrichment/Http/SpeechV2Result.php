<?php

namespace App\Platform\Enrichment\Http;

/**
 * Normalized result of one Speech-to-Text v2 :recognize call (chirp_3):
 * the top alternative per result with the language the model detected for
 * that result (["auto"] = dominant-language detection — the per-result
 * code is the ONLY language signal, spec §2b.9), plus the billed duration
 * when the response metadata carries it (callers feed it to the dominant-
 * language-by-billed-seconds computation, spec §9).
 */
final class SpeechV2Result
{
    /**
     * @param  list<array{transcript: string, confidence: float|null, languageCode: string|null}>  $results
     */
    public function __construct(
        public readonly array $results,
        public readonly ?int $billedSeconds,
    ) {}
}
