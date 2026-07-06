<?php

namespace App\Platform\Enrichment;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Attribution\AttributionService;
use App\Platform\Enrichment\Emv\EmvCalculator;
use App\Platform\Enrichment\Hashtags\HashtagEnricher;
use App\Platform\Enrichment\Models\EnrichmentRun;
use App\Platform\Enrichment\Recognition\RecognitionService;
use App\Platform\Enrichment\Sentiment\SentimentEnricher;
use App\Platform\Enrichment\Support\EnrichmentRunStatus;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The SVC-EnrichmentAI pipeline over one ContentItem or Story:
 *
 *   hashtags → recognition → sentiment → seeded attribution → EMV
 *
 * Stage outcomes are recorded on an EnrichmentRun row (operational
 * telemetry, sanitized values only). Unavailable boundaries (sentiment
 * model, reach method, no active EMV configuration, unconfigured
 * providers) are normal outcomes, not failures — the run still completes
 * and the affected surfaces stay "unavailable". A thrown provider error
 * marks the run FAILED and propagates so the queue's retry policy applies.
 */
class EnrichmentPipeline
{
    public function __construct(
        private readonly HashtagEnricher $hashtags,
        private readonly RecognitionService $recognition,
        private readonly SentimentEnricher $sentiment,
        private readonly AttributionService $attribution,
        private readonly EmvCalculator $emv,
    ) {}

    public function run(ContentItem|Story $target, string $correlationId, int $retryCount = 0): EnrichmentRun
    {
        $run = EnrichmentRun::query()->create([
            $target instanceof ContentItem ? 'content_item_id' : 'story_id' => $target->id,
            'correlation_id' => $correlationId,
            'status' => EnrichmentRunStatus::Running,
            'started_at' => CarbonImmutable::now(),
        ]);

        $stages = [];

        try {
            if ($target instanceof ContentItem) {
                $matches = $this->hashtags->enrich($target);
                $ambiguous = count(array_filter($matches, static fn ($m): bool => $m->isAmbiguous));
                $stages['hashtags'] = 'completed:'.count($matches).($ambiguous > 0 ? " ({$ambiguous} ambiguous → review)" : '');
            } else {
                // ENT-Story carries no caption field.
                $stages['hashtags'] = 'skipped:stories-have-no-caption';
            }

            $recognition = $this->recognition->enrich($target, $correlationId, $retryCount);
            $stages['recognition'] = sprintf(
                '%s:created=%d,updated=%d%s',
                $recognition['status'],
                $recognition['created'],
                $recognition['updated'],
                $recognition['skipped'] !== [] ? ' skipped='.implode('|', $recognition['skipped']) : '',
            );

            $stages['sentiment'] = $target instanceof ContentItem
                ? $this->sentiment->enrich($target)
                : 'skipped:stories-have-no-caption';

            $mentions = $this->attribution->enrich($target);
            $stages['attribution'] = 'completed:'.count($mentions).' mention(s)';

            if ($target instanceof ContentItem) {
                $result = $this->emv->calculate($target);
                $stages['emv'] = $result !== null
                    ? 'calculated:'.$result->formula_version
                    : 'unavailable:no-active-configuration-or-no-inputs';
            } else {
                // MET-EMV is defined "over content"; stories carry no
                // PUBLIC metric inputs for the rate card in v1.
                $stages['emv'] = 'skipped:content-items-only';
            }

            $run->update([
                'status' => EnrichmentRunStatus::Completed,
                'stages' => $stages,
                'finished_at' => CarbonImmutable::now(),
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status' => EnrichmentRunStatus::Failed,
                'stages' => $stages,
                // Sanitized only: classified provider messages are safe;
                // anything else is reduced to its class name.
                'error' => $e instanceof ProviderCallException ? $e->getMessage() : get_class($e),
                'finished_at' => CarbonImmutable::now(),
            ]);

            throw $e;
        }

        return $run;
    }
}
