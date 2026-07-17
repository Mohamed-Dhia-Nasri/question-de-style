<?php

namespace App\Platform\Enrichment\Console;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Jobs\EnrichContentItemJob;
use App\Platform\Enrichment\Jobs\EnrichStoryJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Recovery backstop sweep (ADR-0023): enrichment is dispatched per data
 * pull; this recurring sweep only re-collects recently ingested
 * content/stories whose run crashed or was reaped (no completed or
 * running enrichment run yet). Self-gates on qds.enrichment.enabled like
 * every scheduled QDS command. Its cron is an operational knob of the
 * backstop, not a product cadence.
 */
class RunEnrichmentCommand extends Command
{
    protected $signature = 'qds:run-enrichment
        {--content-item= : Enrich one specific ContentItem id}
        {--story= : Enrich one specific Story id}';

    protected $description = 'Queue SVC-EnrichmentAI over recently ingested, not-yet-enriched content';

    public function handle(): int
    {
        if (! (bool) config('qds.enrichment.enabled')) {
            $this->warn('Enrichment is disabled (qds.enrichment.enabled) — nothing queued.');

            return self::SUCCESS;
        }

        $correlationId = (string) Str::uuid();

        if ($this->option('content-item') !== null || $this->option('story') !== null) {
            return $this->dispatchExplicitTargets($correlationId);
        }

        $windowDays = max(1, (int) config('qds.enrichment.content_window_days'));
        $batch = max(1, (int) config('qds.enrichment.sweep_batch'));
        $since = CarbonImmutable::now()->subDays($windowDays);

        $contentIds = ContentItem::query()
            ->where('published_at', '>=', $since)
            ->whereDoesntHave('enrichmentRuns', static function ($query): void {
                $query->whereIn('status', ['RUNNING', 'COMPLETED']);
            })
            ->orderBy('published_at')
            ->limit($batch)
            ->pluck('id');

        foreach ($contentIds as $id) {
            EnrichContentItemJob::dispatch((int) $id, $correlationId);
        }

        $storyIds = Story::query()
            ->where('captured_at', '>=', $since)
            ->whereDoesntHave('enrichmentRuns', static function ($query): void {
                $query->whereIn('status', ['RUNNING', 'COMPLETED']);
            })
            ->orderBy('captured_at')
            ->limit(max(0, $batch - count($contentIds)))
            ->pluck('id');

        foreach ($storyIds as $id) {
            EnrichStoryJob::dispatch((int) $id, $correlationId);
        }

        $this->info(sprintf(
            'Queued enrichment for %d content item(s) and %d story(ies) [correlation %s].',
            count($contentIds),
            count($storyIds),
            $correlationId,
        ));

        return self::SUCCESS;
    }

    private function dispatchExplicitTargets(string $correlationId): int
    {
        if (($id = $this->option('content-item')) !== null) {
            EnrichContentItemJob::dispatch((int) $id, $correlationId);
            $this->info("Queued enrichment for ContentItem {$id}.");
        }

        if (($id = $this->option('story')) !== null) {
            EnrichStoryJob::dispatch((int) $id, $correlationId);
            $this->info("Queued enrichment for Story {$id}.");
        }

        return self::SUCCESS;
    }
}
