<?php

namespace App\Platform\Enrichment\Hashtags;

use App\Modules\Monitoring\Models\HashtagList;
use App\Platform\Enrichment\Support\HashtagScope;

/**
 * Matches extracted hashtags against the configured campaign / brand /
 * product / agency hashtag lists.
 *
 * Rules (ADR-0008 doctrine, DP-003/DP-004):
 *  - generic hashtags (config `qds.enrichment.hashtags.generic`) never
 *    count as evidence, even when someone configured them in a list;
 *  - a hashtag matching more than one campaign/brand/product target is
 *    AMBIGUOUS and routes to human review instead of counting as evidence;
 *  - a match is only ever supporting evidence — it never proves SEEDED or
 *    PAID on its own.
 */
class HashtagMatcher
{
    /**
     * @param  list<ExtractedHashtag>  $hashtags
     * @return list<HashtagMatch>
     */
    public function match(array $hashtags): array
    {
        if ($hashtags === []) {
            return [];
        }

        /** @var list<string> $generic */
        $generic = array_map(
            static fn ($tag): string => HashtagNormalizer::normalize((string) $tag),
            (array) config('qds.enrichment.hashtags.generic', []),
        );

        $normalizedForms = array_map(static fn (ExtractedHashtag $h): string => $h->normalized, $hashtags);

        $lists = HashtagList::query()
            ->where('active', true)
            ->whereIn('normalized', $normalizedForms)
            ->get()
            ->groupBy('normalized');

        $results = [];

        foreach ($hashtags as $hashtag) {
            if (in_array($hashtag->normalized, $generic, true)) {
                $results[] = new HashtagMatch($hashtag, [], true, false);

                continue;
            }

            /** @var list<array{hashtag_list_id: int, scope: string, campaign_id: int|null, brand_id: int|null, product_label: string|null}> $matches */
            $matches = [];

            foreach ($lists->get($hashtag->normalized, collect()) as $entry) {
                $matches[] = [
                    'hashtag_list_id' => (int) $entry->id,
                    'scope' => $entry->scope->value,
                    'campaign_id' => $entry->campaign_id !== null ? (int) $entry->campaign_id : null,
                    'brand_id' => $entry->brand_id !== null ? (int) $entry->brand_id : null,
                    'product_label' => $entry->product_label,
                ];
            }

            // Ambiguity: the same hashtag names more than one campaign,
            // brand, or product — a human must decide which (DP-004).
            $targeted = array_filter($matches, static fn (array $m): bool => $m['scope'] !== HashtagScope::Agency->value);

            $results[] = new HashtagMatch($hashtag, $matches, false, count($targeted) > 1);
        }

        return $results;
    }
}
