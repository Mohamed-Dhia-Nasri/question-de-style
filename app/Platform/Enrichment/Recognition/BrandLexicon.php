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
                $hits[$brandName] ??= $m[0][1];
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

    /** Lower-case + strip diacritics (NFKD, drop combining marks). */
    private static function fold(string $s): string
    {
        $n = \Normalizer::normalize($s, \Normalizer::FORM_KD);
        $n = is_string($n) ? $n : $s;

        // Strip combining marks (é→e) AND apostrophes (straight U+0027 /
        // curly U+2019) so "L'Oréal", "LOréal" and "loreal" all fold equal.
        return mb_strtolower(preg_replace('/[\p{Mn}\x{2019}\x{0027}]+/u', '', $n) ?? $n);
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
