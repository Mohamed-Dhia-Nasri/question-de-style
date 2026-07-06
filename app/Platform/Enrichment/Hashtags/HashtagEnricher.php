<?php

namespace App\Platform\Enrichment\Hashtags;

use App\Modules\Monitoring\Models\ContentHashtag;
use App\Modules\Monitoring\Models\ContentItem;

/**
 * The hashtag enrichment stage: extracts hashtags from a ContentItem
 * caption, matches them against the configured lists, and persists one
 * content_hashtags row per distinct tag (original preserved verbatim).
 *
 * Idempotent upsert keyed on (content_item_id, normalized). A human
 * resolution of an ambiguous match (resolved_* columns) is NEVER touched
 * by a re-run — later AI runs never overwrite human corrections (DP-004).
 *
 * Stories carry no caption field (ENT-Story), so hashtag extraction
 * applies to ContentItems only.
 */
class HashtagEnricher
{
    public function __construct(
        private readonly HashtagExtractor $extractor,
        private readonly HashtagMatcher $matcher,
    ) {}

    /** @return list<HashtagMatch> */
    public function enrich(ContentItem $contentItem): array
    {
        $extracted = $this->extractor->extract($contentItem->caption);

        $matches = $this->matcher->match($extracted);

        foreach ($matches as $match) {
            $row = ContentHashtag::query()->firstOrNew([
                'content_item_id' => $contentItem->id,
                'normalized' => $match->hashtag->normalized,
            ]);

            $row->fill([
                'original' => $match->hashtag->original,
                'first_position' => $match->hashtag->firstPosition,
                'occurrences' => $match->hashtag->occurrences,
                'matches' => $match->matches,
                // A resolved row keeps its human decision; ambiguity only
                // re-opens when the row was never resolved.
                'is_ambiguous' => $row->resolved_at !== null ? false : $match->isAmbiguous,
            ]);

            $row->save();
        }

        return $matches;
    }
}
