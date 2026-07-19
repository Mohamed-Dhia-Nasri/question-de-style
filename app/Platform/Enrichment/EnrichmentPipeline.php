<?php

namespace App\Platform\Enrichment;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Attribution\AttributionService;
use App\Platform\Enrichment\Emv\EmvCalculator;
use App\Platform\Enrichment\Hashtags\HashtagEnricher;
use App\Platform\Enrichment\Media\MediaWorkspaceFactory;
use App\Platform\Enrichment\Models\EnrichmentRun;
use App\Platform\Enrichment\Reach\ReachCalculator;
use App\Platform\Enrichment\Recognition\RecognitionService;
use App\Platform\Enrichment\Sentiment\SentimentEnricher;
use App\Platform\Enrichment\Support\EnrichmentRunStatus;
use App\Platform\Enrichment\TextSignals\TextSignalRecognizer;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The SVC-EnrichmentAI pipeline over one ContentItem or Story:
 *
 *   hashtags → recognition → text signals → sentiment → seeded attribution → EMV → reach
 *
 * Stage outcomes are recorded on an EnrichmentRun row (operational
 * telemetry, sanitized values only). Unavailable boundaries (sentiment
 * model, no active EMV/reach configuration, unconfigured providers) are
 * normal outcomes, not failures — the run still completes and the
 * affected surfaces stay "unavailable". A thrown provider error marks the
 * run FAILED and propagates so the queue's retry policy applies.
 */
class EnrichmentPipeline
{
    public function __construct(
        private readonly HashtagEnricher $hashtags,
        private readonly RecognitionService $recognition,
        private readonly TextSignalRecognizer $textSignals,
        private readonly SentimentEnricher $sentiment,
        private readonly AttributionService $attribution,
        private readonly EmvCalculator $emv,
        private readonly ReachCalculator $reach,
        private readonly MediaWorkspaceFactory $workspaces,
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
        $workspace = $this->workspaces->forTarget($target);

        try {
            if ($target instanceof ContentItem) {
                $matches = $this->hashtags->enrich($target);
                $ambiguous = count(array_filter($matches, static fn ($m): bool => $m->isAmbiguous));
                $stages['hashtags'] = 'completed:'.count($matches).($ambiguous > 0 ? " ({$ambiguous} ambiguous → review)" : '');
            } else {
                // ENT-Story carries no caption field.
                $stages['hashtags'] = 'skipped:stories-have-no-caption';
            }

            $recognition = $this->recognition->enrich($target, $correlationId, $retryCount, $workspace);
            $stages['recognition'] = sprintf(
                '%s:created=%d,updated=%d%s',
                $recognition['status'],
                $recognition['created'],
                $recognition['updated'],
                $recognition['skipped'] !== [] ? ' skipped='.implode('|', $recognition['skipped']) : '',
            );

            if (config('qds.enrichment.text_signals.enabled')) {
                $stages['text_signals'] = $this->textSignals->enrich($target);
            } else {
                $stages['text_signals'] = 'skipped:disabled';
            }

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

            if ($target instanceof ContentItem) {
                $reachResult = $this->reach->calculate($target);
                $stages['reach'] = $reachResult !== null
                    ? 'calculated:'.$reachResult->formula_version
                    : 'unavailable:no-active-configuration-or-no-inputs';
            } else {
                // Reach is modeled over content metrics + follower signals;
                // stories carry neither in v1.
                $stages['reach'] = 'skipped:content-items-only';
            }

            // Compare-and-swap on RUNNING: if the data-quality reaper already
            // marked this row FAILED (a genuine over-run past the stale
            // window), do NOT silently un-fail it back to COMPLETED — that
            // would erase the reap evidence and hide both the hang and the
            // duplicate sweep the reaper triggered.
            EnrichmentRun::query()
                ->whereKey($run->getKey())
                ->where('status', EnrichmentRunStatus::Running->value)
                ->update([
                    'status' => EnrichmentRunStatus::Completed,
                    'stages' => $stages,
                    'finished_at' => CarbonImmutable::now(),
                ]);

            // Reflect the DB truth in the returned model — the completed
            // stages when we won the swap, or the reaper's FAILED verdict if
            // it beat us (0 rows updated).
            $run->refresh();
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
        } finally {
            $workspace->close();
        }

        return $run;
    }
}
