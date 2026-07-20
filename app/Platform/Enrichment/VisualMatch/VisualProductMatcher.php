<?php

namespace App\Platform\Enrichment\VisualMatch;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\KeyframeEmbedding;
use App\Modules\Monitoring\Models\Story;
use App\Platform\AiBudget\AiBudgetGuard;
use App\Platform\Enrichment\Keyframes\KeyframeRepository;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateScope;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateSet;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparation;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparationResult;
use App\Platform\Enrichment\VisualMatch\Frames\KeyframeEmbedder;
use App\Platform\Enrichment\VisualMatch\Frames\PreparedFrame;
use App\Platform\Enrichment\VisualMatch\Matching\BandMapper;
use App\Platform\Enrichment\VisualMatch\Matching\BandResult;
use App\Platform\Enrichment\VisualMatch\Matching\FrameProductScorer;
use App\Platform\Ingestion\Observability\ProviderCircuitBreaker;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\Enums\VisualMatchOutcome;

/**
 * The visual_match stage orchestrator (sub-project C): gates → candidate
 * scoping → free local frame preparation → budget-guarded embedding →
 * exact-scan scoring → banding → persistence. Fail-closed: every gate
 * exits with an explainable marker; unavailability is recorded at run
 * level, never fabricated as detection rows. Runs under the enrichment
 * job's TenantContext::runAs.
 */
final class VisualProductMatcher
{
    private const CAPABILITY = 'embedding';

    public function __construct(
        private readonly EmbeddingProvider $provider,
        private readonly CandidateScope $candidates,
        private readonly KeyframeRepository $keyframes,
        private readonly FramePreparation $preparation,
        private readonly KeyframeEmbedder $embedder,
        private readonly FrameProductScorer $scorer,
        private readonly BandMapper $bands,
        private readonly VisualMatchWriter $writer,
        private readonly VisualMatchRunRecorder $recorder,
        private readonly AiBudgetGuard $budget,
        private readonly ProviderCircuitBreaker $breaker,
    ) {}

    /** @return string the EnrichmentRun stage marker (frozen set, spec §8) */
    public function enrich(ContentItem|Story $target, string $correlationId): string
    {
        if (! (bool) config('qds.enrichment.visual_match.enabled')) {
            return 'skipped:disabled';
        }

        if (! $this->provider->isConfigured()) {
            return 'skipped:not-configured';
        }

        if ($target->platformAccount?->creator_id === null) {
            return 'skipped:no-creator';
        }

        $startedAt = microtime(true);
        $tenantId = (int) $target->tenant_id;

        $candidates = $this->candidates->forTarget($target);

        if ($candidates->isEmpty()) {
            // The tiering that makes most posts free: no plausible product,
            // no spend, no run row — only the usage counter learns of it.
            $this->budget->record(self::CAPABILITY, $tenantId, 0, postsSkippedNoCandidates: 1);

            return 'skipped:no-candidates';
        }

        $set = $this->keyframes->forOwner($target);

        if ($set->isEmpty()) {
            // B could not extract: nothing for C OR D to see (no escalation).
            return 'skipped:no-frames';
        }

        // Local + free. The stage's own frame cap and the budget guard's
        // per-post ceiling agree by default (both 12); the stricter wins.
        $prep = $this->preparation->prepare($set, min(
            (int) config('qds.enrichment.visual_match.frame_budget'),
            (int) config('qds.ai_budget.capabilities.embedding.per_post_units'),
        ));

        $modelVersion = $this->provider->modelVersion();
        $matchable = $candidates->matchable();

        if ($matchable === [] || $prep->frames === []) {
            // Nothing scorable (no embedded reference photos, or no frame
            // survived preparation): zero spend, but the run IS recorded —
            // the coverage accounting is exactly what D and reviewers need.
            return $this->complete($target, $correlationId, $candidates, $prep, [], $modelVersion, 0, 0, $startedAt, $tenantId, spend: false, scoredFrames: count($prep->frames));
        }

        // Paid path from here: consult the breaker BEFORE spending —
        // every call bills (deliberate improvement over recognition).
        if ($this->breaker->shouldSkip(SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS)) {
            $this->recordSkip($target, $correlationId, $candidates, $prep, VisualMatchOutcome::SkippedProvider, $modelVersion, 0, 0, $startedAt);

            return 'skipped:provider-unavailable';
        }

        $decision = $this->budget->allows(self::CAPABILITY, $tenantId, $this->projectedCalls($prep, $modelVersion), $candidates->priority);

        if (! $decision->allowed) {
            if ($decision->reason === 'read-only') {
                $this->recordSkip($target, $correlationId, $candidates, $prep, VisualMatchOutcome::SkippedReadOnly, $modelVersion, 0, 0, $startedAt);

                return 'skipped:ai-read-only';
            }

            $this->budget->record(self::CAPABILITY, $tenantId, 0, postsSkippedBudget: 1);
            $this->recordSkip($target, $correlationId, $candidates, $prep, VisualMatchOutcome::SkippedBudget, $modelVersion, 0, 0, $startedAt);

            return 'skipped:budget-exhausted';
        }

        $embedding = $this->embedder->embedAll($prep->frames, $correlationId);

        if ($embedding['embedded'] === []) {
            // Transient provider trouble on every frame: a run-level marker
            // (mirroring speech:provider-error) — never a failed enrichment
            // run, never a re-bill of completed stages; cached rows are kept.
            $this->budget->record(self::CAPABILITY, $tenantId, $embedding['billedCalls']);
            $this->recordSkip($target, $correlationId, $candidates, $prep, VisualMatchOutcome::SkippedProvider, $modelVersion, $embedding['billedCalls'], $embedding['cacheHits'], $startedAt);

            return 'skipped:provider-error';
        }

        $this->budget->record(self::CAPABILITY, $tenantId, $embedding['billedCalls'], postsProcessed: 1);

        // Score only the frames that actually embedded (transient-failure omission).
        $scorable = array_values(array_filter(
            $prep->frames,
            static fn (PreparedFrame $frame): bool => array_key_exists($frame->keyframe->id, $embedding['embedded']),
        ));

        $results = $this->bands->map($this->scorer->score($scorable, $matchable, $modelVersion), $prep);

        return $this->complete($target, $correlationId, $candidates, $prep, $results, $modelVersion, $embedding['billedCalls'], $embedding['cacheHits'], $startedAt, $tenantId, spend: true, scoredFrames: count($scorable));
    }

    /** @param list<BandResult> $results */
    private function complete(ContentItem|Story $target, string $correlationId, CandidateSet $candidates,
        FramePreparationResult $prep, array $results, string $modelVersion,
        int $billedCalls, int $cacheHits, float $startedAt, int $tenantId, bool $spend, int $scoredFrames): string
    {
        if (! $spend) {
            $this->budget->record(self::CAPABILITY, $tenantId, 0, postsProcessed: 1);
        }

        $auto = $review = $reject = 0;

        foreach ($results as $result) {
            if ($result->band === VisualMatchBand::Reject) {
                $reject++;
                // Catalog/model drift: an earlier AI row whose candidate now
                // rejects downgrades to review — never deleted (DP-004).
                $this->writer->withdrawSupport($target, $result->candidate->productId);

                continue;
            }

            $result->band === VisualMatchBand::Auto ? $auto++ : $review++;
            $this->writer->write($target, $result, $modelVersion);
        }

        $outcome = $this->bands->runOutcome($results, $prep, $candidates);

        // Unavailable ≠ false (spec §8/§11). BandMapper::runOutcome's
        // signature is frozen (the eval command consumes it), so this seam
        // catches what its prep-time coverage counters (skippedFormat/
        // skippedQuality) cannot see: embed-time failures. A transient
        // failure on SOME prepared frames still lets the rest score clean,
        // so runOutcome sees no banding and would report a clean NO_MATCH —
        // but we did not actually look at every frame. Downgrade to
        // INCONCLUSIVE instead of masquerading as "looked and did not see it".
        if ($outcome === VisualMatchOutcome::NoMatch && $scoredFrames < count($prep->frames)) {
            $outcome = VisualMatchOutcome::Inconclusive;
        }

        $this->recorder->record($target, $correlationId, $candidates, $prep, $results, $outcome,
            $modelVersion, $billedCalls, $cacheHits, $this->elapsedMs($startedAt), $this->needsVerification($results, $candidates));

        return match ($outcome) {
            VisualMatchOutcome::NoMatch => 'completed:no-match',
            VisualMatchOutcome::Inconclusive => 'completed:inconclusive',
            default => sprintf('completed:matched=%d,review=%d,rejected=%d', $auto, $review, $reject),
        };
    }

    private function recordSkip(ContentItem|Story $target, string $correlationId, CandidateSet $candidates,
        FramePreparationResult $prep, VisualMatchOutcome $outcome, string $modelVersion,
        int $billedCalls, int $cacheHits, float $startedAt): void
    {
        // A skipped run assessed nothing: needs_verification stays false —
        // the backfill command is the remedy, not D's verifier.
        $this->recorder->record($target, $correlationId, $candidates, $prep, [], $outcome,
            $modelVersion, $billedCalls, $cacheHits, $this->elapsedMs($startedAt), false);
    }

    /**
     * §11: D verifies lone REVIEW hits, and shipment-but-no-match runs
     * regardless of the no_match/inconclusive split. AUTO needs no help.
     *
     * @param list<BandResult> $results
     */
    private function needsVerification(array $results, CandidateSet $candidates): bool
    {
        $auto = false;
        $review = false;

        foreach ($results as $result) {
            $auto = $auto || $result->band === VisualMatchBand::Auto;
            $review = $review || $result->band === VisualMatchBand::Review;
        }

        return match (true) {
            $auto => false,
            $review => true,
            default => $candidates->hasInWindowShipment(),
        };
    }

    /** Projected UNCACHED call count — what the budget guard is asked for. */
    private function projectedCalls(FramePreparationResult $prep, string $modelVersion): int
    {
        $ids = array_map(static fn (PreparedFrame $frame): int => $frame->keyframe->id, $prep->frames);

        $cached = KeyframeEmbedding::query()
            ->whereIn('keyframe_id', $ids)
            ->where('model_version', $modelVersion)
            ->count();

        return max(0, count($ids) - $cached);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
