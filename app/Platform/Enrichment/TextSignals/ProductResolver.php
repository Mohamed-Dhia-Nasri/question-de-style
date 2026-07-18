<?php

namespace App\Platform\Enrichment\TextSignals;

use App\Modules\CRM\Models\Product;
use App\Platform\Ingestion\DTO\ProductTag;
use Illuminate\Support\Collection;

/**
 * Resolves a product tag or caption text onto a CRM product via the
 * ladder: exact SKU > name/variant > aliases. Tenant-scoped (Product is
 * BelongsToTenant); deterministic; never guesses. The caption name/variant
 * rung requires the product's brand to be present in the post (generic-name
 * guard) so shared names ("Lip Balm") cannot create false hits.
 */
final class ProductResolver
{
    public function resolveTag(ProductTag $tag): ?ResolvedProduct
    {
        if ($tag->productSku !== null) {
            $bySku = $this->catalog()->first(fn (Product $p): bool => $p->sku !== null && self::fold($p->sku) === self::fold($tag->productSku));

            if ($bySku !== null) {
                return $this->make($bySku, 'sku');
            }
        }

        foreach ([$tag->productName] as $name) {
            if ($name === null) {
                continue;
            }

            $byName = $this->catalog()->first(fn (Product $p): bool => $this->nameMatches($p, $name));

            if ($byName !== null) {
                return $this->make($byName, 'name');
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $brandsPresent  brand names already evidenced in the post
     * @return list<ResolvedProduct>
     */
    public function matchInCaption(string $caption, array $brandsPresent): array
    {
        $folded = self::fold($caption);
        $present = array_map(self::fold(...), $brandsPresent);
        $out = [];

        foreach ($this->catalog() as $product) {
            if (! $this->nameAppears($product, $folded)) {
                continue;
            }

            // Generic-name guard: a caption name/variant match only counts
            // when the product's brand is independently present in the post.
            if (! in_array(self::fold($product->brand->name), $present, true)) {
                continue;
            }

            $out[$product->id] = $this->make($product, 'name');
        }

        return array_values($out);
    }

    /** @return Collection<int, Product> */
    private function catalog(): Collection
    {
        // Tenant-scoped by the model's global scope; eager-load brand for name.
        return Product::query()->with('brand')->get();
    }

    private function nameMatches(Product $p, string $name): bool
    {
        $needle = self::fold($name);

        if ($needle === '') {
            return false;
        }

        if (self::fold($p->name) === $needle || ($p->variant !== null && self::fold($p->variant) === $needle)) {
            return true;
        }

        foreach ($p->aliases ?? [] as $alias) {
            if (self::fold((string) $alias) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function nameAppears(Product $p, string $foldedHaystack): bool
    {
        foreach (array_filter([$p->name, $p->variant, ...($p->aliases ?? [])]) as $candidate) {
            $folded = self::fold((string) $candidate);

            if ($folded !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($folded, '/').'(?![\p{L}\p{N}])/u', $foldedHaystack) === 1) {
                return true;
            }
        }

        return false;
    }

    private function make(Product $p, string $rung): ResolvedProduct
    {
        return new ResolvedProduct($p->id, $p->name, $p->brand_id, $p->brand->name, $rung);
    }

    private static function fold(string $s): string
    {
        $n = \Normalizer::normalize($s, \Normalizer::FORM_KD);
        $n = is_string($n) ? $n : $s;

        return mb_strtolower(preg_replace('/\p{Mn}+/u', '', $n) ?? $n);
    }
}
