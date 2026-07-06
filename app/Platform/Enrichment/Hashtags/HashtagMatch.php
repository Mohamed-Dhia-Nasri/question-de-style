<?php

namespace App\Platform\Enrichment\Hashtags;

/**
 * Match outcome for one extracted hashtag against the configured hashtag
 * lists: zero or more list hits, plus the generic/ambiguous flags that
 * govern its evidential weight (generic tags carry none; ambiguous tags
 * route to review — DP-004).
 */
final readonly class HashtagMatch
{
    public function __construct(
        public ExtractedHashtag $hashtag,
        /**
         * @var list<array{hashtag_list_id: int, scope: string, campaign_id: int|null, brand_id: int|null, product_label: string|null}>
         */
        public array $matches,
        public bool $isGeneric,
        public bool $isAmbiguous,
    ) {}
}
