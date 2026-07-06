<?php

namespace App\Platform\Enrichment\Hashtags;

/**
 * Extracts hashtags from caption text and supported provider metadata.
 * Unicode-aware: a hashtag is '#' followed by letters, marks, numbers, or
 * underscores in any script (so #café, #мода, and #スキンケア all extract).
 * Duplicate tags (after normalization) collapse into one ExtractedHashtag
 * with an occurrence count; the first verbatim original is preserved.
 */
class HashtagExtractor
{
    private const PATTERN = '/#([\p{L}\p{M}\p{N}_]+)/u';

    /**
     * @param  array<int, mixed>  $metadataTags  hashtag strings a provider
     *                                           exposes as structured
     *                                           metadata (no '#' required;
     *                                           non-strings are skipped)
     * @return list<ExtractedHashtag>
     */
    public function extract(?string $caption, array $metadataTags = []): array
    {
        /** @var array<string, ExtractedHashtag> $found keyed by normalized form */
        $found = [];

        if (is_string($caption) && $caption !== '') {
            preg_match_all(self::PATTERN, $caption, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $match) {
                [$original, $offset] = $match;

                $this->collect($found, $original, (int) $offset);
            }
        }

        foreach ($metadataTags as $tag) {
            if (! is_string($tag) || trim($tag, "# \t\n\r") === '') {
                continue;
            }

            $original = str_starts_with($tag, '#') ? $tag : '#'.$tag;

            $this->collect($found, $original, 0);
        }

        return array_values($found);
    }

    /** @param array<string, ExtractedHashtag> $found */
    private function collect(array &$found, string $original, int $offset): void
    {
        $normalized = HashtagNormalizer::normalize($original);

        if ($normalized === '') {
            return;
        }

        $existing = $found[$normalized] ?? null;

        $found[$normalized] = $existing === null
            ? new ExtractedHashtag($original, $normalized, $offset, 1)
            : new ExtractedHashtag($existing->original, $normalized, $existing->firstPosition, $existing->occurrences + 1);
    }
}
