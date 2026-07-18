<?php

namespace App\Platform\Enrichment\Recognition;

use App\Modules\CRM\Models\Brand;

/**
 * Known-brand matching for recognition outputs: maps a raw provider label
 * or a text fragment onto a CRM brand via its name or configured aliases
 * (brands.aliases). Matching is deterministic and case-insensitive; it
 * never guesses — an unmatched label yields null.
 *
 * Product inference is deliberately absent: a brand-level signal (logo,
 * brand text) is never narrowed to a specific product here, because a
 * brand may have several products (enrichment doctrine).
 */
class BrandLexicon
{
    /** Aliases shorter than this cannot match inside free text (noise guard). */
    private const MIN_TEXT_MATCH_LENGTH = 3;

    /** @var array<string, string>|null lowercased alias → brand name */
    private ?array $lexicon = null;

    /** Exact (case-insensitive) label match, e.g. a Vision logo description. */
    public function matchLabel(string $label): ?string
    {
        $needle = mb_strtolower(trim($label));

        if ($needle === '') {
            return null;
        }

        return $this->lexicon()[$needle] ?? null;
    }

    /** First known brand appearing inside a text (OCR, transcript, on-screen). */
    public function matchInText(string $text): ?string
    {
        $haystack = mb_strtolower($text);

        foreach ($this->lexicon() as $alias => $brandName) {
            if (mb_strlen($alias) < self::MIN_TEXT_MATCH_LENGTH) {
                continue;
            }

            // Require a whole-word match (unicode-aware) rather than bare
            // substring containment — a short alias like 'mac' must not match
            // inside 'making'/'macaroni' and auto-accept a false detection (M26).
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($alias, '/').'(?![\p{L}\p{N}])/u';

            if (preg_match($pattern, $haystack) === 1) {
                return $brandName;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function lexicon(): array
    {
        if ($this->lexicon !== null) {
            return $this->lexicon;
        }

        $lexicon = [];

        foreach (Brand::query()->get(['name', 'aliases']) as $brand) {
            $lexicon[mb_strtolower($brand->name)] = $brand->name;

            foreach ($brand->aliases ?? [] as $alias) {
                $alias = trim((string) $alias);

                if ($alias !== '') {
                    $lexicon[mb_strtolower($alias)] = $brand->name;
                }
            }
        }

        return $this->lexicon = $lexicon;
    }
}
