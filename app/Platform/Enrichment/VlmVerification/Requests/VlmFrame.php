<?php

namespace App\Platform\Enrichment\VlmVerification\Requests;

/**
 * One prepared keyframe as the VLM sees it (spec §6): a stable prompt name
 * (FRAME_1, FRAME_2, … in timestamp order), the timestamp the app maps
 * frame references back to (null for carousel images/thumbnails), and the
 * bytes that travel INLINE base64 — no URL ever reaches the provider
 * (DP-005).
 */
final readonly class VlmFrame
{
    public function __construct(
        public string $name,
        public ?int $timestampMs,
        public string $bytes,
        public string $mimeType,
    ) {}
}
