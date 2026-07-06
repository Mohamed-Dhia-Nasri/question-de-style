<?php

namespace App\Platform\Enrichment\Hashtags;

/**
 * One hashtag extracted from a caption: the verbatim original (preserved,
 * including '#'), the normalized matching form, the byte offset of the
 * first occurrence, and how often it occurred.
 */
final readonly class ExtractedHashtag
{
    public function __construct(
        public string $original,
        public string $normalized,
        public int $firstPosition,
        public int $occurrences,
    ) {}
}
