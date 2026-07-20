<?php

namespace Tests\Support;

use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;

/**
 * Deterministic container stub for the EmbeddingProvider seam: counts
 * calls, records mime types, never touches the network, and returns a
 * fixed vector at the DDL width — vector(3072) rejects any other length.
 */
final class FakeEmbeddingProvider implements EmbeddingProvider
{
    public int $calls = 0;

    /** @var list<string> mime types seen, in call order */
    public array $mimeTypes = [];

    public function __construct(
        private readonly bool $configured = true,
        private readonly string $modelVersion = 'gemini-embedding-2',
        public float $fill = 0.001,
    ) {}

    /** @return list<float> */
    public function embedImage(string $bytes, string $mimeType): array
    {
        $this->calls++;
        $this->mimeTypes[] = $mimeType;

        return array_fill(0, 3072, $this->fill);
    }

    public function modelVersion(): string
    {
        return $this->modelVersion;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }
}
