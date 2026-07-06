<?php

namespace App\Platform\Enrichment;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Contracts\EnrichmentService;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * SVC-EnrichmentAI (L3) — the P1 implementation behind the declared
 * EnrichmentService boundary. Accepts the Module 1 enrichable records
 * (ContentItem, Story) and runs the full pipeline synchronously; queued
 * execution goes through EnrichContentItemJob / EnrichStoryJob.
 */
class DefaultEnrichmentService implements EnrichmentService
{
    public function __construct(private readonly EnrichmentPipeline $pipeline) {}

    public function enrich(object $record): void
    {
        if (! $record instanceof ContentItem && ! $record instanceof Story) {
            throw new InvalidArgumentException(
                'SVC-EnrichmentAI enriches ContentItem or Story records; got '.$record::class.'.'
            );
        }

        $this->pipeline->run($record, (string) Str::uuid());
    }
}
