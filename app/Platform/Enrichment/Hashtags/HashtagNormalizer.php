<?php

namespace App\Platform\Enrichment\Hashtags;

use Normalizer;

/**
 * Unicode-safe, case-insensitive hashtag normalization: NFKC normalization
 * (folds compatibility variants, full-width forms, combining sequences)
 * followed by a UTF-8 case fold, with any leading '#' stripped. The
 * ORIGINAL form is always preserved alongside the normalized form — the
 * normalized form exists only for matching.
 */
final class HashtagNormalizer
{
    public static function normalize(string $hashtag): string
    {
        $bare = ltrim(trim($hashtag), '#');

        $normalized = Normalizer::normalize($bare, Normalizer::FORM_KC);

        if ($normalized === false) {
            // Not a well-formed Unicode string — fall back to the raw form
            // so matching still behaves deterministically.
            $normalized = $bare;
        }

        return mb_strtolower($normalized, 'UTF-8');
    }

    private function __construct() {}
}
