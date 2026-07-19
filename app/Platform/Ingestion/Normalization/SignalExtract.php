<?php

namespace App\Platform\Ingestion\Normalization;

use App\Platform\Ingestion\DTO\ProductTag;

/**
 * Fail-closed mapping of provider payload fields onto ContentData signal
 * fields. Any missing/malformed key yields empty/null — never fabricated.
 *
 * @phpstan-type Item array<array-key, mixed>
 */
final class SignalExtract
{
    /** @param array<array-key, mixed> $item @return list<string> */
    public static function mentions(array $item): array
    {
        return self::stringList($item['mentions'] ?? null);
    }

    /** @param array<array-key, mixed> $item @return list<string> */
    public static function collaborators(array $item): array
    {
        return self::stringList($item['coauthorProducers'] ?? $item['collaborators'] ?? null);
    }

    /** @param array<array-key, mixed> $item @return list<ProductTag> */
    public static function productTags(array $item): array
    {
        $raw = $item['productTags'] ?? $item['taggedProducts'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $tags = [];

        foreach ($raw as $t) {
            if (! is_array($t)) {
                continue;
            }

            $tags[] = new ProductTag(
                brandRef: self::str($t['brand'] ?? $t['brandName'] ?? null),
                productName: self::str($t['name'] ?? $t['productName'] ?? $t['title'] ?? null),
                productSku: self::str($t['sku'] ?? null),
                providerTagId: self::str($t['id'] ?? $t['productId'] ?? null),
            );
        }

        return $tags;
    }

    /** @param array<array-key, mixed> $item */
    public static function brandedContentLabel(array $item): ?bool
    {
        $v = $item['isSponsored'] ?? $item['paidPartnership'] ?? $item['isPaidPartnership'] ?? null;

        return is_bool($v) ? $v : null;
    }

    /** @param mixed $value @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $v) {
            $s = self::str(is_array($v) ? ($v['username'] ?? $v['name'] ?? null) : $v);

            if ($s !== null) {
                $out[] = ltrim($s, '@');
            }
        }

        return array_values(array_unique($out));
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
