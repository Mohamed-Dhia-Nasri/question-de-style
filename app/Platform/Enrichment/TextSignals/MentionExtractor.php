<?php

namespace App\Platform\Enrichment\TextSignals;

/**
 * Extracts @mention handles from caption text (Instagram/TikTok handle
 * grammar: letters, digits, '.', '_'). Lower-cased, de-duplicated,
 * first-seen order. Never fabricates — null/empty caption → [].
 */
final class MentionExtractor
{
    private const PATTERN = '/@([A-Za-z0-9._]+)/';

    /** @return list<string> */
    public function extract(?string $caption): array
    {
        if (! is_string($caption) || $caption === '') {
            return [];
        }

        preg_match_all(self::PATTERN, $caption, $matches);

        $out = [];

        foreach ($matches[1] as $handle) {
            $handle = mb_strtolower(rtrim($handle, '.'));

            if ($handle !== '' && ! in_array($handle, $out, true)) {
                $out[] = $handle;
            }
        }

        return $out;
    }
}
