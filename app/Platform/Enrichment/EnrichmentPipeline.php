<?php

namespace App\Platform\Enrichment;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Platform\Enrichment\Attribution\AttributionService;
use App\Platform\Enrichment\Emv\EmvCalculator;
use App\Platform\Enrichment\Hashtags\HashtagEnricher;
use App\Platform\Enrichment\Keyframes\KeyframeExtractor;
use App\Platform\Enrichment\Media\MediaWorkspaceFactory;
use App\Platform\Enrichment\Models\EnrichmentRun;
use App\Platform\Enrichment\Reach\ReachCalculator;
use App\Platform\Enrichment\Recognition\RecognitionService;
use App\Platform\Enrichment\Sentiment\SentimentEnricher;
use App\Platform\Enrichment\Support\EnrichmentRunStatus;
use App\Platform\Enrichment\TextSignals\TextSignalRecognizer;
use App\Platform\Enrichment\Transcripts\YouTubeTranscriptEnricher;
use App\Platform\Enrichment\VisualMatch\VisualProductMatcher;
use App\Platform\Enrichment\VlmVerification\Jobs\VlmVerificationJob;
use App\Platform\Enrichment\VlmVerification\VlmRunRecorder;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The SVC-EnrichmentAI pipeline over one ContentItem or Story:
 *
 *   hashtags → transcript → recognition → keyframes → visual match → vlm verification (dispatch-only) → text signals → sentiment → seeded attribution → EMV → reach
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
        private readonly KeyframeExtractor $keyframes,
        private readonly YouTubeTranscriptEnricher $transcripts,
        private readonly VisualProductMatcher $visualMatch,
        private readonly VlmRunRecorder $vlmRuns,
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

            $stages['transcript'] = $this->transcripts->enrich($target, $correlationId, $retryCount);

            $recognition = $this->recognition->enrich($target, $correlationId, $retryCount, $workspace);
            $stages['recognition'] = sprintf(
                '%s:created=%d,updated=%d%s',
                $recognition['status'],
                $recognition['created'],
                $recognition['updated'],
                $recognition['skipped'] !== [] ? ' skipped='.implode('|', $recognition['skipped']) : '',
            );

            if ((bool) config('qds.enrichment.keyframes.enabled')) {
                // Runs even with no Google provider configured — frames are
                // for tiers C/D, independent of the recognition providers.
                $stages['keyframes'] = $this->keyframes->enrich($target, $workspace);
            } else {
                $stages['keyframes'] = 'skipped:disabled';
            }

            // Sub-project C: visual product matching over the persisted
            // keyframes. After `keyframes` (frames must exist), before
            // `attribution` in the same run so VISUAL_PRODUCT detections
            // classify immediately. Kill switch OFF = marker only — the
            // matcher (and its provider chain) is never invoked.
            if ((bool) config('qds.enrichment.visual_match.enabled')) {
                $stages['visual_match'] = $this->visualMatch->enrich($target, $correlationId);
            } else {
                $stages['visual_match'] = 'skipped:disabled';
            }

            // Sub-project D: VLM verification is DISPATCH-ONLY — the
            // pipeline never blocks on Gemini. The async job re-checks
            // every gate itself (flags go stale between dispatch and
            // execution), so this stage only answers "is there anything
            // to verify right now?" and gives the common case a same-run
            // head start over the daily qds:vlm-verify sweep. Kill switch
            // OFF = marker only; nothing is ever queued.
            $stages['vlm_verification'] = $this->dispatchVlmVerification($target, $correlationId);

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

    /**
     * The vlm_verification trigger stage (sub-project D, spec §4/§10).
     * Frozen marker set: skipped:disabled | skipped:no-visual-run |
     * skipped:not-flagged | skipped:already-verified | queued.
     *
     * Consumption bookkeeping lives in vlm_verification_runs (the partial
     * unique on (visual_match_run_id, model_version)) — a TERMINAL row at
     * the current model version means "already verified"; PENDING rows do
     * NOT block, because a crashed job needs its dispatch back to resume
     * the billing ledger.
     */
    private function dispatchVlmVerification(ContentItem|Story $target, string $correlationId): string
    {
        if (! (bool) config('qds.enrichment.vlm.enabled')) {
            return 'skipped:disabled';
        }

        // "Latest run per post = max id" — C's index contract.
        $anchor = VisualMatchRun::query()
            ->when(
                $target instanceof ContentItem,
                fn ($query) => $query->where('content_item_id', $target->id),
                fn ($query) => $query->where('story_id', $target->id),
            )
            ->orderByDesc('id')
            ->first();

        if ($anchor === null) {
            return 'skipped:no-visual-run';
        }

        if (! $anchor->needs_verification) {
            return 'skipped:not-flagged';
        }

        if ($this->vlmRuns->terminalRunExists($anchor, (string) config('qds.enrichment.vlm.model_version'))) {
            return 'skipped:already-verified';
        }

        // The enrichment correlation id rides along: the job derives
        // review-band / no-band-shipment from the anchor's candidates
        // (a NULL correlation id is reserved for sweep dispatches).
        VlmVerificationJob::dispatch(
            $target instanceof ContentItem ? 'content' : 'story',
            (int) $target->id,
            $correlationId,
        );

        return 'queued';
    }
}
