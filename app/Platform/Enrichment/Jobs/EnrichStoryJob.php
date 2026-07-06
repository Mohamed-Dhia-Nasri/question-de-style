<?php

namespace App\Platform\Enrichment\Jobs;

use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\EnrichmentPipeline;
use App\Platform\Ingestion\Jobs\Concerns\IngestionJobBehaviour;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queued enrichment of one archived Story (recognition + attribution;
 * stories carry no caption, so hashtags/sentiment don't apply).
 *
 * ShouldBeUnique keeps a second copy for the same Story off the queue so an
 * overlapping sweep cannot run a concurrent pass over the same target.
 */
class EnrichStoryJob implements ShouldBeUnique, ShouldQueue
{
    use IngestionJobBehaviour;
    use Queueable;

    public int $tries = 4;

    public int $timeout = 600;

    /** Enrichment jobs run outside monitoring cycles. */
    public readonly ?int $cycleId;

    public function __construct(
        public readonly int $storyId,
        public readonly string $correlationId,
    ) {
        $this->cycleId = null;
        $this->onQueue('enrichment');
    }

    public function uniqueId(): string
    {
        return 'qds-enrich-story:'.$this->storyId;
    }

    public function uniqueFor(): int
    {
        return $this->timeout + 60;
    }

    public function handle(EnrichmentPipeline $pipeline): void
    {
        $this->attachLogContext();

        $story = Story::query()->find($this->storyId);

        if ($story === null) {
            return;
        }

        try {
            $pipeline->run($story, $this->correlationId, max(0, $this->attempts() - 1));
        } catch (Throwable $e) {
            $this->handleProviderFailure($e);
        }
    }
}
