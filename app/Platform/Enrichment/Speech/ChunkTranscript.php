<?php

namespace App\Platform\Enrichment\Speech;

/**
 * One transcribed audio chunk of a post (sub-project D, spec §9): the
 * chunk-local text plus the offsets D computed ITSELF — provider
 * word-level offsets are at-risk on chirp_3 (spec §2b.11), so chunk-level
 * timing is authoritative. Ordinal 0 is the in-pipeline sync pass;
 * ordinals 1..N are the persisted extension chunks.
 */
final readonly class ChunkTranscript
{
    public function __construct(
        public int $ordinal,
        public int $offsetMs,
        public int $durationMs,
        public string $text,
        public ?string $languageCode,
        public ?float $confidence,
    ) {}
}
