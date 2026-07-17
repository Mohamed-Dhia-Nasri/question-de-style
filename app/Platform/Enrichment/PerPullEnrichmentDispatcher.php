<?php

namespace App\Platform\Enrichment;

use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Enrichment\Jobs\EnrichContentItemJob;
use App\Platform\Enrichment\Jobs\EnrichStoryJob;
use Carbon\CarbonImmutable;

/**
 * ADR-0023: enrichment follows the data pull. Ingestion calls this with
 * the rows it CREATED (metric refreshes never re-trigger — EMV/reach
 * results are append-only and recognition re-bills). The recurring sweep
 * (qds:run-enrichment) stays scheduled as the recovery backstop: its
 * RUNNING/COMPLETED predicate makes it a no-op for anything enriched here.
 *
 * Every dispatch honours the qds.enrichment.enabled kill switch, and
 * content honours the sweep's eligibility window so deep backfills of old
 * posts cannot trigger surprise recognition cost.
 */
class PerPullEnrichmentDispatcher
{
    /** @param list<int> $createdIds */
    public function dispatchForContent(array $createdIds, string $correlationId): void
    {
        if ($createdIds === [] || ! config('qds.enrichment.enabled')) {
            return;
        }

        $windowDays = max(1, (int) config('qds.enrichment.content_window_days'));

        $eligibleIds = ContentItem::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $createdIds)
            ->where('published_at', '>=', CarbonImmutable::now()->subDays($windowDays))
            ->pluck('id');

        foreach ($eligibleIds as $id) {
            EnrichContentItemJob::dispatch((int) $id, $correlationId);
        }
    }

    /** Stories enrich only AFTER their media archive lands (recognition needs the file). */
    public function dispatchForStory(int $storyId, string $correlationId): void
    {
        if (! config('qds.enrichment.enabled')) {
            return;
        }

        EnrichStoryJob::dispatch($storyId, $correlationId);
    }
}
