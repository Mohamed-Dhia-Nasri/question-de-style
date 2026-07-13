<?php

namespace App\Platform\Enrichment\Jobs;

use App\Modules\Monitoring\Models\ContentItem;
use App\Platform\Enrichment\EnrichmentPipeline;
use App\Platform\Ingestion\Jobs\Concerns\IngestionJobBehaviour;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queued enrichment of one ContentItem. Carries only scalar identifiers;
 * transient provider failures retry with backoff, permanent ones fail
 * fast (IngestionJobBehaviour), and the EnrichmentRun row records the
 * sanitized outcome either way.
 *
 * ShouldBeUnique keeps a second copy for the same ContentItem off the queue
 * so an overlapping sweep cannot enqueue a concurrent pass over the same
 * target (which would race the mention/detection upserts).
 */
class EnrichContentItemJob implements ShouldBeUnique, ShouldQueue
{
    use IngestionJobBehaviour;
    use Queueable;

    public int $tries = 4;

    public int $timeout = 600;

    /** Enrichment jobs run outside monitoring cycles. */
    public readonly ?int $cycleId;

    public function __construct(
        public readonly int $contentItemId,
        public readonly string $correlationId,
    ) {
        $this->cycleId = null;
        $this->onQueue('enrichment');
    }

    public function uniqueId(): string
    {
        return 'qds-enrich-content:'.$this->contentItemId;
    }

    public function uniqueFor(): int
    {
        return $this->timeout + 60;
    }

    public function handle(EnrichmentPipeline $pipeline): void
    {
        $this->attachLogContext();

        $contentItem = ContentItem::query()->find($this->contentItemId);

        if ($contentItem === null) {
            return;
        }

        try {
            // ADR-0019: enrichment sweeps run tenant-less — the content item
            // row is the aggregate root; running the pipeline under its
            // tenant makes every enrichment write (mentions, detections,
            // sentiment, hashtags, EMV results, enrichment runs) stamp the
            // right owner, and scopes the per-tenant ACTIVE EmvConfiguration.
            app(TenantContext::class)->runAs(
                $contentItem->tenant_id,
                fn () => $pipeline->run($contentItem, $this->correlationId, max(0, $this->attempts() - 1)),
            );
        } catch (Throwable $e) {
            $this->handleProviderFailure($e);
        }
    }
}
