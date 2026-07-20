<?php

namespace App\Platform\Enrichment\VisualMatch\Matching;

use App\Shared\Enums\SectorLabel;

/**
 * Category-keyed threshold resolution (spec §8/§12): a per-category entry
 * (key = SectorLabel value, e.g. packaging-prone BEAUTY) overrides the
 * 'default' entry key-by-key. The config values are deliberate
 * PLACEHOLDERS — calibration against the eval golden set is sub-project
 * E's mandate; the code fallbacks mirror spec §12 so the resolver is safe
 * even before Task 19 lands the full config block.
 */
final class ThresholdResolver
{
    private const DEFAULT_AUTO = 0.65;

    private const DEFAULT_REVIEW = 0.55;

    private const DEFAULT_MARGIN = 0.05;

    public function for(?SectorLabel $category): Thresholds
    {
        $map = (array) config('qds.enrichment.visual_match.thresholds', []);
        $default = is_array($map['default'] ?? null) ? $map['default'] : [];
        $override = $category !== null && is_array($map[$category->value] ?? null) ? $map[$category->value] : [];

        return new Thresholds(
            auto: (float) ($override['auto'] ?? $default['auto'] ?? self::DEFAULT_AUTO),
            review: (float) ($override['review'] ?? $default['review'] ?? self::DEFAULT_REVIEW),
            margin: (float) ($override['margin'] ?? $default['margin'] ?? self::DEFAULT_MARGIN),
        );
    }
}
