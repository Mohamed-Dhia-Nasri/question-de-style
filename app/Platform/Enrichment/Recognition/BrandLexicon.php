<?php

namespace App\Platform\Enrichment\Recognition;

use App\Modules\CRM\Models\Brand;

/**
 * Known-brand matching for recognition + caption text. Deterministic,
 * case- AND diacritic-insensitive, whole-word; never guesses. Product
 * inference stays out of here (enrichment doctrine).
 */
class BrandLexicon
{
    private const MIN_TEXT_MATCH_LENGTH = 3;

    /** @var array<string, string>|null folded alias → brand name */
    private ?array $lexicon = null;

    /** @var array<string, string>|null folded handle → brand name */
    private ?array $handles = null;

    /** Exact (folded) label match, e.g. a Vision logo description. */
    public function matchLabel(string $label): ?string
    {
        $needle = self::fold($label);

        return $needle === '' ? null : ($this->lexicon()[$needle] ?? null);
    }

    /** First known brand appearing in a text. */
    public function matchInText(string $text): ?string
    {
        return $this->matchAllInText($text)[0] ?? null;
    }

    /**
     * ALL distinct known brands appearing in a text, in first-occurrence
     * order (M26 whole-word guard; folded so "loreal" matches "L'Oréal").
     *
     * @return list<string>
     */
    public function matchAllInText(string $text): array
    {
        $haystack = self::fold($text);
        $hits = [];

        foreach ($this->lexicon() as $alias => $brandName) {
            if (mb_strlen($alias) < self::MIN_TEXT_MATCH_LENGTH && ! $this->isAllowedShort($alias)) {
                continue;
            }

            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($alias, '/').'(?![\p{L}\p{N}])/u', $haystack, $m, PREG_OFFSET_CAPTURE) === 1) {
                // A brand can have several matching keys (name + aliases); keep
                // the EARLIEST text offset so first-occurrence order is honest.
                $hits[$brandName] = isset($hits[$brandName]) ? min($hits[$brandName], $m[0][1]) : $m[0][1];
            }
        }

        asort($hits); // order by first offset

        return array_keys($hits);
    }

    /** '@glossier' | 'glossier' → brand name via brands.social_handles, else null. */
    public function resolveHandle(string $handle): ?string
    {
        $needle = self::fold(ltrim(trim($handle), '@'));

        return $needle === '' ? null : ($this->handles()[$needle] ?? null);
    }

    private function isAllowedShort(string $foldedAlias): bool
    {
        foreach ((array) config('qds.enrichment.text_signals.short_brand_allowlist', []) as $s) {
            if (self::fold((string) $s) === $foldedAlias) {
                return true;
            }
        }

        return false;
    }

    /** Lower-case + strip diacritics (NFKD) + trim. Apostrophes are KEPT so a
     *  possessive ("Nike's") still yields a word boundary after the brand; an
     *  apostrophe-in-name brand ("L'Oréal") matches its no-apostrophe form via a
     *  configured alias ("loreal"), not by stripping punctuation (which would
     *  glue the possessive 's onto the name and break whole-word matching). */
    private static function fold(string $s): string
    {
        $n = \Normalizer::normalize(trim($s), \Normalizer::FORM_KD);
        $n = is_string($n) ? $n : $s;

        return mb_strtolower(preg_replace('/\p{Mn}+/u', '', $n) ?? $n);
    }

    /** @return array<string, string> */
    private function lexicon(): array
    {
        if ($this->lexicon !== null) {
            return $this->lexicon;
        }

        $lexicon = [];

        foreach (Brand::query()->get(['name', 'aliases']) as $brand) {
            $lexicon[self::fold($brand->name)] = $brand->name;

            foreach ($brand->aliases ?? [] as $alias) {
                $alias = trim((string) $alias);

                if ($alias !== '') {
                    $lexicon[self::fold($alias)] = $brand->name;
                }
            }
        }

        return $this->lexicon = $lexicon;
    }

    /** @return array<string, string> */
    private function handles(): array
    {
        if ($this->handles !== null) {
            return $this->handles;
        }

        $handles = [];

        foreach (Brand::query()->get(['name', 'social_handles']) as $brand) {
            foreach ($brand->social_handles ?? [] as $handle) {
                $handle = self::fold(ltrim(trim((string) $handle), '@'));

                if ($handle !== '') {
                    $handles[$handle] = $brand->name;
                }
            }
        }

        return $this->handles = $handles;
    }
}
