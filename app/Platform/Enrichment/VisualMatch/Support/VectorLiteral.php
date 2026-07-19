<?php

namespace App\Platform\Enrichment\VisualMatch\Support;

use InvalidArgumentException;

/**
 * pgvector text-literal formatting/parsing — the ONLY vector serialization
 * in the codebase (no composer dependency, ADR-0029). pgvector accepts
 * `'[0.1,0.2,...]'::vector` as input and prints the same shape on `::text`
 * output; PHP 8's locale-independent shortest-round-trip float casting
 * keeps the conversion lossless at the precision similarity scoring needs.
 */
final class VectorLiteral
{
    /** @param list<float> $vector */
    public static function fromArray(array $vector): string
    {
        if ($vector === []) {
            throw new InvalidArgumentException('A pgvector literal needs at least one dimension.');
        }

        $parts = [];

        foreach ($vector as $component) {
            $component = (float) $component;

            if (! is_finite($component)) {
                throw new InvalidArgumentException('Vector components must be finite (NAN/INF rejected).');
            }

            // json_encode uses serialize_precision=-1 for exact round-trip representation,
            // whereas (string) uses the precision ini setting (lossy, default 14 digits).
            $parts[] = json_encode($component);
        }

        return '['.implode(',', $parts).']';
    }

    /** @return list<float> */
    public static function toArray(string $literal): array
    {
        $trimmed = trim($literal);

        if (! str_starts_with($trimmed, '[') || ! str_ends_with($trimmed, ']')) {
            throw new InvalidArgumentException("Not a pgvector literal: [{$literal}]");
        }

        $body = substr($trimmed, 1, -1);

        if (trim($body) === '') {
            throw new InvalidArgumentException('A pgvector literal needs at least one dimension.');
        }

        $components = [];

        foreach (explode(',', $body) as $component) {
            $component = trim($component);

            if ($component === '' || ! is_numeric($component)) {
                throw new InvalidArgumentException("Malformed vector component [{$component}].");
            }

            $components[] = (float) $component;
        }

        return $components;
    }
}
