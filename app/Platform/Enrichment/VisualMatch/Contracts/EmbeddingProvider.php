<?php

namespace App\Platform\Enrichment\VisualMatch\Contracts;

/**
 * The embedding seam for visual product matching (sub-project C, spec §5):
 * container-bound so the matcher, embedders, and persistence never learn
 * which provider produced a vector. A second provider (or the v2
 * cropped-region variant) is a new binding plus a new model_version —
 * zero call-site changes. YAGNI: no provider-selection config knob until
 * a second implementation exists.
 */
interface EmbeddingProvider
{
    /**
     * Embed ONE image into ONE vector. One call per image is a verified
     * model property (2026-07-19): multi-image input FUSES into a single
     * vector, which is useless for per-frame matching.
     *
     * @return list<float>
     *
     * @throws \App\Platform\Ingestion\Exceptions\ProviderCallException classified failure — never a raw provider error
     */
    public function embedImage(string $bytes, string $mimeType): array;

    /**
     * Stamped on every embedding row; changing it is a re-embed backfill,
     * never an in-place mutation (vector spaces are incompatible).
     */
    public function modelVersion(): string;

    public function isConfigured(): bool;
}
