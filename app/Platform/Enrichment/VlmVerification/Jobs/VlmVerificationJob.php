<?php

namespace App\Platform\Enrichment\VlmVerification\Jobs;

use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Services\ActiveSeedingCreatorIds;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Modules\Monitoring\Models\VlmVerificationRun;
use App\Platform\AiBudget\AiBudgetGuard;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\Attribution\AttributionService;
use App\Platform\Enrichment\Support\AiPayloadGuard;
use App\Platform\Enrichment\VlmVerification\Banding\VlmBandMapper;
use App\Platform\Enrichment\VlmVerification\Http\GeminiVlmClient;
use App\Platform\Enrichment\VlmVerification\Requests\VlmRequestBuilder;
use App\Platform\Enrichment\VlmVerification\Verdicts\VerdictValidator;
use App\Platform\Enrichment\VlmVerification\VlmDetectionWriter;
use App\Platform\Enrichment\VlmVerification\VlmRunRecorder;
use App\Platform\Ingestion\DTO\ProviderResponse;
use App\Platform\Ingestion\Exceptions\ProviderCallException;
use App\Platform\Ingestion\Jobs\Concerns\IngestionJobBehaviour;
use App\Platform\Ingestion\Observability\ProviderCallRecorder;
use App\Platform\Ingestion\Observability\ProviderCircuitBreaker;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\Enums\VlmBand;
use App\Shared\Enums\VlmRunOutcome;
use App\Shared\Enums\VlmTriggerReason;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Async VLM verification of one escalated post (sub-project D, spec §10):
 * gates → request → Gemini generateContent (EU) → validate/ground → band →
 * VLM_PRODUCT detections → vlm_verification_runs/_verdicts → re-classify.
 *
 * Failure doctrine: a VLM failure never fails or blocks any enrichment
 * run — fail-closed skip is the worst case; evidence stays absent and the
 * mention stands wherever the cheaper tiers put it.
 *
 * DEFERRAL conditions write NO run row (the anchor stays unconsumed and
 * the qds:vlm-verify sweep retries them when the condition clears):
 * kill switch off, target/anchor gone, already consumed, provider not
 * configured, breaker open, read-only mode, budget deny. TERMINAL
 * conditions write/finalize a run row (consumed): frames pruned, payload
 * guard trip, safety block, malformed after the ceiling, tries exhausted
 * after billing.
 *
 * Crash-safe billing ledger: the run row opens as `pending` and survives
 * crashes; `attempts` is incremented and committed BEFORE every provider
 * call, so job retries RESUME the count and the per_post_units ceiling
 * binds across all executions — job-level $tries can never multiply it.
 */
final class VlmVerificationJob implements ShouldBeUnique, ShouldQueue
{
    use IngestionJobBehaviour {
        failed as private ingestionJobFailed;
    }
    use Queueable;

    private const CAPABILITY = 'vlm_verification';

    public int $tries = 4;

    public int $timeout = 180;

    /** VLM jobs run outside monitoring cycles. */
    public readonly ?int $cycleId;

    public function __construct(
        public readonly string $targetType, // 'content'|'story'
        public readonly int $targetId,
        public readonly ?string $correlationId = null,
    ) {
        $this->cycleId = null;
        $this->onQueue((string) config('qds.enrichment.vlm.queue'));
    }

    public function uniqueId(): string
    {
        return "vlm-verify:{$this->targetType}:{$this->targetId}";
    }

    public function uniqueFor(): int
    {
        return $this->timeout + 60;
    }

    public function handle(
        GeminiVlmClient $client,
        VlmRequestBuilder $builder,
        VerdictValidator $validator,
        VlmBandMapper $bands,
        VlmDetectionWriter $writer,
        VlmRunRecorder $recorder,
        AiBudgetGuard $budget,
        ProviderCircuitBreaker $breaker,
        AttributionService $attribution,
        TenantContext $tenants,
        ProviderCallRecorder $telemetry,
    ): void {
        $this->attachLogContext();

        if (! (bool) config('qds.enrichment.vlm.enabled')) {
            return; // kill switch: true no-op (deferral class — no row)
        }

        $target = $this->resolveTarget();

        if ($target === null) {
            return; // stale job: the post was deleted or erased
        }

        try {
            // ADR-0019: queue workers run tenant-less; every write below
            // (runs, verdicts, detections, mentions) must stamp the post's
            // owner — the EnrichContentItemJob precedent.
            $tenants->runAs(
                (int) $target->tenant_id,
                fn () => $this->verify($target, $client, $builder, $validator, $bands, $writer, $recorder, $budget, $breaker, $attribution, $telemetry),
            );
        } catch (Throwable $e) {
            $this->handleProviderFailure($e);
        }
    }

    private function verify(
        ContentItem|Story $target,
        GeminiVlmClient $client,
        VlmRequestBuilder $builder,
        VerdictValidator $validator,
        VlmBandMapper $bands,
        VlmDetectionWriter $writer,
        VlmRunRecorder $recorder,
        AiBudgetGuard $budget,
        ProviderCircuitBreaker $breaker,
        AttributionService $attribution,
        ProviderCallRecorder $telemetry,
    ): void {
        $anchor = $this->latestAnchor($target);

        if ($anchor === null || ! $anchor->needs_verification) {
            return; // not escalated (or the flag was superseded) — no-op
        }

        $modelVersion = $client->modelVersion();

        if ($recorder->terminalRunExists($anchor, $modelVersion)) {
            return; // consumption idempotency: already verified at this model
        }

        if (! $client->isConfigured()) {
            Log::info('vlm-verification deferred: provider not configured');

            return; // deferral — the sweep retries once credentials land
        }

        // Paid path from here: consult the breaker BEFORE spending (C's
        // deliberate improvement over recognition).
        if ($breaker->shouldSkip(SourceRegistry::GOOGLE_GEMINI_VLM)) {
            Log::info('vlm-verification deferred: provider breaker open');

            return; // deferral
        }

        $startedAt = microtime(true);
        $tenantId = (int) $target->tenant_id;
        $correlationId = $this->correlationId ?? (string) Str::uuid();
        $reason = $this->triggerReason($anchor);
        $priority = $this->resolvePriority($anchor);

        $request = $builder->build($target, $anchor);

        if ($request === null) {
            // Frames pruned between flag and job — never coming back:
            // terminal, consumed (spec §10 gate table).
            $run = $recorder->open($target, $anchor, $reason, $priority, $modelVersion, $correlationId, 0);
            $recorder->finalize($run, VlmRunOutcome::SkippedNoFrames, null, [], null, null, null, $this->elapsedMs($startedAt), 'no-frames');

            return;
        }

        try {
            // Approved fail-closed posture (spec §6/§12): the guard REJECTS,
            // never redacts — and runs on the textual view only, BEFORE any
            // token fetch or byte leaves. Deterministic on this content ⇒
            // terminal skip, not a retry.
            AiPayloadGuard::assertSafe($request->textualPayload());
        } catch (InvalidArgumentException) {
            $run = $recorder->open($target, $anchor, $reason, $priority, $modelVersion, $correlationId, count($request->frames));
            $recorder->finalize($run, VlmRunOutcome::SkippedPayloadGuard, null, [], null, null, null, $this->elapsedMs($startedAt), 'payload-guard');

            return;
        }

        // Creates the pending ledger row — or RESUMES a crashed execution's
        // row for (anchor, model_version), attempts intact.
        $run = $recorder->open($target, $anchor, $reason, $priority, $modelVersion, $correlationId, count($request->frames));

        $maxAttempts = max(1, (int) config('qds.ai_budget.capabilities.vlm_verification.per_post_units'));
        $lastMalformed = null;

        try {
            while (true) {
                if ($run->attempts >= $maxAttempts) {
                    // The job enforces the ceiling itself: HIGH priority
                    // bypasses the guard's per-post check by design, and the
                    // ledger makes the ceiling hold across ALL executions.
                    $recorder->finalize($run, VlmRunOutcome::FailedMalformed, null, [], null, null, null, $this->elapsedMs($startedAt), $lastMalformed ?? 'attempt-ceiling');

                    return;
                }

                // Cumulative count as units: allows(n) makes the guard's
                // per-post ceiling actually bind for Medium (a flat
                // allows(1) never would — C's aggregate-projection rule).
                $decision = $budget->allows(self::CAPABILITY, $tenantId, $run->attempts + 1, $priority);

                if (! $decision->allowed) {
                    if ($run->attempts === 0) {
                        // Nothing billed: unconsume — the sweep retries this
                        // anchor once budget clears (deferral leaves NO row).
                        $recorder->deleteUnbilled($run);
                    }

                    if ($decision->reason !== 'read-only') {
                        $budget->record(self::CAPABILITY, $tenantId, 0, postsSkippedBudget: 1);
                    }

                    Log::info('vlm-verification deferred: budget', ['reason' => $decision->reason]);

                    return;
                }

                // Crash-safe ledger: the billed attempt is committed BEFORE
                // the call — a worker crash or timeout kill can never forget
                // a billed attempt, so the ceiling can never be exceeded.
                $recorder->incrementAttempts($run);
                $budget->record(self::CAPABILITY, $tenantId, 1);

                // Spec §5 telemetry: every provider call lands in
                // provider_calls under (SRC-google-gemini-vlm, vlm.verify)
                // and drives the health state the breaker consult above
                // reads — the client's frozen contract returns bare results,
                // so the wrap lives at this seam (KeyframeEmbedder
                // precedent). Bare autocommit writes, NEVER a transaction:
                // the billing ledger's crash-safety rule owns this loop.
                $call = $telemetry->start(
                    SourceRegistry::GOOGLE_GEMINI_VLM,
                    'vlm.verify',
                    $correlationId,
                    null,
                    $target->platform_account_id === null ? null : (int) $target->platform_account_id,
                    $run->attempts - 1,
                );
                $callStartedAt = microtime(true);

                try {
                    $result = $client->verify($request);
                } catch (ProviderCallException $e) {
                    // Failure telemetry (health/breaker/dashboards) lands
                    // BEFORE the outer catch decides resume-vs-unconsume.
                    $telemetry->recordFailure($call, $e);

                    throw $e;
                }

                // recordOperation needs a ProviderResponse; the decoded JSON
                // is the only payload visible at this seam, so its serialized
                // size is the honest response-size proxy (no fabricated
                // fields). Blocked/empty responses completed but yielded
                // zero usable results.
                $telemetry->recordOperation($call, new ProviderResponse(
                    items: [],
                    httpStatus: 200,
                    responseBytes: strlen((string) json_encode($result->json)),
                    requestMs: (microtime(true) - $callStartedAt) * 1000,
                    sourceVersion: $modelVersion,
                ), $result->json === [] ? 0 : 1);

                if ($result->blockReason !== null) {
                    // Safety blocks are PERMANENT (spec §5): no retry, no
                    // budget refund — the blocking call billed (HTTP 200).
                    $recorder->finalize($run, VlmRunOutcome::SkippedSafetyBlock, null, [], $result->promptTokens, $result->outputTokens, $result->thinkingTokens, $this->elapsedMs($startedAt), $result->blockReason);

                    return;
                }

                $validation = $validator->validate($result->json, $request);

                if ($validation->verdicts === null) {
                    // Malformed output (spec §6): retry the same request —
                    // bounded by the attempts ceiling above.
                    $lastMalformed = $validation->malformedReason;

                    continue;
                }

                $set = $validation->verdicts;

                $outcome = match ($set->outcome) {
                    'PRODUCT_CONFIRMED' => VlmRunOutcome::Confirmed,
                    'PRODUCT_ABSENT' => VlmRunOutcome::Absent,
                    default => VlmRunOutcome::Inconclusive,
                };

                $bandResults = $bands->map($set, $request);

                // INCONCLUSIVE = "could not judge" — never detection writes,
                // never withdrawals (unavailable ≠ absent, spec §7).
                if ($outcome !== VlmRunOutcome::Inconclusive) {
                    foreach ($bandResults as $bandResult) {
                        if ($bandResult->band === VlmBand::Reject) {
                            // C's withdraw pattern: an earlier AI VLM row whose
                            // candidate now rejects downgrades — never deleted.
                            $writer->withdrawSupport($target, $bandResult->verdict->productId);

                            continue;
                        }

                        $writer->write($target, $bandResult, $modelVersion);
                    }
                }

                $recorder->finalize($run, $outcome, $set, $bandResults, $result->promptTokens, $result->outputTokens, $result->thinkingTokens, $this->elapsedMs($startedAt));
                $budget->record(self::CAPABILITY, $tenantId, 0, postsProcessed: 1);

                // Re-classify in the SAME tenant context so the fresh VLM
                // evidence lands on the Mention now (backfill precedent —
                // AttributionService::enrich's third caller).
                $attribution->enrich($target);

                return;
            }
        } catch (ProviderCallException $e) {
            if ($e->category->isTransient() && (int) $run->attempts === 0) {
                // Nothing billed: unconsume — the queue retry is free by
                // construction. With attempts > 0 the pending row survives
                // and the retried execution RESUMES it (ledger authority).
                $recorder->deleteUnbilled($run);
            }

            throw $e; // handle() routes it through handleProviderFailure
        }
    }

    /**
     * Final failure (tries exhausted or permanent category): make the
     * ledger truthful before the trait raises the JOB_FAILED critical
     * alert — billed pending rows finalize as skipped_provider (money
     * spent, nothing learned — consumed; a model_version bump or new C run
     * is the re-open path, never silent re-billing), unbilled ones are
     * deleted so the anchor stays sweep-eligible.
     */
    public function failed(?Throwable $exception): void
    {
        $target = $this->resolveTarget();

        if ($target !== null) {
            app(TenantContext::class)->runAs((int) $target->tenant_id, function () use ($target): void {
                $recorder = app(VlmRunRecorder::class);

                $run = VlmVerificationRun::query()
                    ->where($target instanceof ContentItem ? 'content_item_id' : 'story_id', $target->id)
                    ->where('outcome', VlmRunOutcome::Pending->value)
                    ->orderByDesc('id')
                    ->first();

                if ($run === null) {
                    return;
                }

                (int) $run->attempts === 0
                    ? $recorder->deleteUnbilled($run)
                    : $recorder->finalize($run, VlmRunOutcome::SkippedProvider, null, [], null, null, null, 0, 'job-failed');
            });
        }

        $this->ingestionJobFailed($exception);
    }

    private function resolveTarget(): ContentItem|Story|null
    {
        return match ($this->targetType) {
            'content' => ContentItem::query()->find($this->targetId),
            'story' => Story::query()->find($this->targetId),
            default => null,
        };
    }

    private function latestAnchor(ContentItem|Story $target): ?VisualMatchRun
    {
        // "Latest run per post = max id" — C's index contract.
        return VisualMatchRun::query()
            ->where($target instanceof ContentItem ? 'content_item_id' : 'story_id', $target->id)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Frozen derivation rule: the sweep dispatches WITHOUT a correlation id
     * (the job mints its own) ⇒ sweep-catchup; the inline stage always
     * passes the enrichment run's id ⇒ re-derive review-band vs
     * no-band-shipment from the anchor's persisted candidate bands (§4).
     */
    private function triggerReason(VisualMatchRun $anchor): VlmTriggerReason
    {
        if ($this->correlationId === null) {
            return VlmTriggerReason::SweepCatchup;
        }

        return $anchor->candidates()->where('band', VisualMatchBand::Review->value)->exists()
            ? VlmTriggerReason::ReviewBand
            : VlmTriggerReason::NoBandShipment;
    }

    /**
     * §4: HIGH when the anchor's persisted candidates include an
     * ACTIVE/SHIPPING-campaign source — roster by construction, or a
     * shipment whose campaign is CURRENTLY active (re-resolved from the
     * candidate rows, not trusted from C's stamp: the campaign may have
     * ended between C's run and this job). Else MEDIUM.
     */
    private function resolvePriority(VisualMatchRun $anchor): Priority
    {
        $candidates = $anchor->candidates()->get();

        foreach ($candidates as $candidate) {
            if ($candidate->source === 'roster') {
                return Priority::High;
            }
        }

        $campaignIds = $candidates->pluck('seeding_campaign_id')->filter()->unique()->values()->all();

        if ($campaignIds !== [] && SeedingCampaign::query()
            ->whereIn('id', $campaignIds)
            ->whereIn('status', ActiveSeedingCreatorIds::statusValues())
            ->exists()) {
            return Priority::High;
        }

        return Priority::Medium;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
