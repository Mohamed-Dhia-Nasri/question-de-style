<?php

namespace App\Platform\Enrichment\Speech;

use App\Modules\CRM\Models\Brand;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateSet;

/**
 * Adaptation phrase hints for Speech-to-Text v2 (sub-project D, spec §9):
 * the tenant's brand names + aliases plus the post's candidate
 * product/brand names (the same candidate scoping C uses), deduplicated,
 * deterministically ordered (brands by id, name before aliases, then
 * candidates in set order), capped at qds.enrichment.speech.phrase_cap
 * (default 500; the chirp_3 dictionary hard limit is 1,000). Tenant
 * isolation rides on Brand's BelongsToTenant under the enrichment job's
 * TenantContext.
 */
final class SpeechPhraseHints
{
    /** @return list<string> */
    public function build(CandidateSet $candidates): array
    {
        $phrases = [];

        foreach (Brand::query()->orderBy('id')->get() as $brand) {
            $phrases[] = (string) $brand->name;

            foreach ((array) $brand->aliases as $alias) {
                if (is_string($alias)) {
                    $phrases[] = $alias;
                }
            }
        }

        foreach ($candidates->candidates as $candidate) {
            $phrases[] = $candidate->brandName;
            $phrases[] = $candidate->productLabel;
        }

        $phrases = array_values(array_unique(array_filter(
            array_map(static fn (string $phrase): string => trim($phrase), $phrases),
            static fn (string $phrase): bool => $phrase !== '',
        )));

        return array_slice($phrases, 0, max(0, (int) config('qds.enrichment.speech.phrase_cap')));
    }
}
