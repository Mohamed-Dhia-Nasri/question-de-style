<?php

namespace App\Platform\Enrichment\Media;

/** One downloaded (or disk-materialized) media file inside a MediaWorkspace. */
final readonly class LocalMediaAsset
{
    public function __construct(
        public string $tempPath,
        public int $byteSize,
        public ?string $contentType,
        public string $sha256,
        /** Null when materialized from the private story archive. */
        public ?string $sourceUrl,
    ) {}

    /** Inline payload for the Google providers (small assets only). */
    public function bytes(): string
    {
        return (string) file_get_contents($this->tempPath);
    }
}
