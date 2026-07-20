<?php

namespace App\Platform\Enrichment\VlmVerification;

use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Modules\Monitoring\Models\VlmCandidateVerdict;
use App\Modules\Monitoring\Models\VlmVerificationRun;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\VlmVerification\Banding\VlmBandResult;
use App\Platform\Enrichment\VlmVerification\Verdicts\CandidateVerdict;
use App\Platform\Enrichment\VlmVerification\Verdicts\VerdictSet;
use App\Shared\Enums\VlmRunOutcome;
use App\Shared\Enums\VlmTriggerReason;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * The crash-safe billing ledger for VLM verification (spec §8/§10):
 * append-only vlm_verification_runs rows with a single pending→terminal
 * lifecycle, attempts committed BEFORE each provider call (a worker crash
 * or timeout kill can never forget a billed attempt), per-candidate
 * verdicts for sub-project E's "Gemini agreement" input, and the DEF-021
 * 'unverifiable' discovery rows the sweep writes (never sent to Gemini —
 * "we could not look" is a recorded fact, never product absence).
 * Consumption bookkeeping = the partial unique on (visual_match_run_id,
 * model_version): one verification per anchor per VLM model — a model
 * upgrade re-opens old anchors, catalog changes ride new anchor ids.
 * Deferrable skips (budget / read-only / provider-unavailable before any
 * billed call) write NO row at all — that is the CALLER's rule; this class
 * only ever writes pending, terminal, or unverifiable rows.
 */
final class VlmRunRecorder
{
    /** Both rejection_reason columns are varchar(100) — see truncate(). */
    private const REASON_MAX_CHARS = 100;

    /**
     * Creates the pending ledger row — or RESUMES the existing pending row
     * for (anchor, model_version), attempts intact: a crashed or
     * timeout-killed execution left it behind and the retried job must
     * continue the SAME billing count, never start a fresh one (§10). The
     * original correlation_id is preserved on resume (append-only audit).
     */
    public function open(ContentItem|Story $target, ?VisualMatchRun $anchor, VlmTriggerReason $reason,
        Priority $priority, string $modelVersion, string $correlationId, int $framesSent): VlmVerificationRun
    {
        if ($anchor !== null) {
            $pending = VlmVerificationRun::query()
                ->where('visual_match_run_id', $anchor->id)
                ->where('model_version', $modelVersion)
                ->where('outcome', VlmRunOutcome::Pending->value)
                ->first();

            if ($pending !== null) {
                return $pending;
            }
        }

        return VlmVerificationRun::query()->create([
            $target instanceof ContentItem ? 'content_item_id' : 'story_id' => $target->id,
            'visual_match_run_id' => $anchor?->id,
            'correlation_id' => $correlationId,
            'model_version' => $modelVersion,
            'trigger_reason' => $reason->value,
            'priority' => $priority->value,
            'frames_sent' => $framesSent,
            'attempts' => 0,
            'outcome' => VlmRunOutcome::Pending->value,
            'thresholds' => $this->thresholdSnapshot(),
            'latency_ms' => 0,
            'estimated_cost_micro_usd' => 0,
        ]);
    }

    /**
     * Committed BEFORE the provider call (§10 crash-safe ledger): a crash
     * between this increment and the response wastes at most that one call
     * and can never exceed the per-post ceiling. The job is ShouldBeUnique
     * — one writer per run — so the read-modify-write is race-free.
     */
    public function incrementAttempts(VlmVerificationRun $run): void
    {
        if ($run->outcome !== VlmRunOutcome::Pending) {
            throw new InvalidArgumentException('Attempts are billed against a pending run only.');
        }

        $run->forceFill([
            'attempts' => $run->attempts + 1,
            'estimated_cost_micro_usd' => ($run->attempts + 1)
                * (int) config('qds.ai_budget.capabilities.vlm_verification.price_micro_usd_per_unit'),
        ])->save();
    }

    /**
     * The single pending→terminal transition — never re-opened (append-only:
     * a re-verification is a NEW row under a new anchor or model_version).
     * Verdicts persist for every real response — confirmed, absent, AND
     * inconclusive (sub-project E reads them) — with band/rejection_reason
     * from the mapper when bands were computed, band null otherwise (§8.2
     * nullable band). Boundary guard (VisualMatchRunRecorder precedent):
     * a skipped or failed run assessed nothing, so it can never carry
     * verdicts — enforced BEFORE any write lands.
     *
     * @param  list<VlmBandResult>  $bands ranked best-first (VlmBandMapper order)
     */
    public function finalize(VlmVerificationRun $run, VlmRunOutcome $outcome, ?VerdictSet $set,
        array $bands, ?int $promptTokens, ?int $outputTokens,
        ?int $thinkingTokens, int $latencyMs, ?string $rejectionReason = null): void
    {
        if ($run->outcome !== VlmRunOutcome::Pending) {
            throw new InvalidArgumentException('A VLM run is finalized exactly once: pending -> terminal.');
        }

        if ($outcome === VlmRunOutcome::Pending) {
            throw new InvalidArgumentException('Pending is not a terminal outcome.');
        }

        $responseOutcomes = [VlmRunOutcome::Confirmed, VlmRunOutcome::Absent, VlmRunOutcome::Inconclusive];

        if (! in_array($outcome, $responseOutcomes, true) && ($set !== null || $bands !== [])) {
            throw new InvalidArgumentException('A skipped or failed VLM run records no candidate verdicts.');
        }

        $run->forceFill([
            'outcome' => $outcome->value,
            'prompt_tokens' => $promptTokens,
            'output_tokens' => $outputTokens,
            'thinking_tokens' => $thinkingTokens,
            'latency_ms' => $latencyMs,
            'rejection_reason' => $this->truncate($rejectionReason),
        ])->save();

        $rank = 0;

        if ($bands !== []) {
            foreach ($bands as $result) {
                $this->verdictRow($run, $result->verdict, ++$rank, $result->band->value, $result->rejectionReason);
            }

            return;
        }

        if ($set === null) {
            return;
        }

        // No bands were computed (e.g. a §6-normalized INCONCLUSIVE run):
        // verdicts still persist for E, band null, ranked by confidence
        // desc then lower product id (determinism doctrine).
        $ordered = $set->verdicts;
        usort($ordered, fn (CandidateVerdict $a, CandidateVerdict $b): int => [$b->confidence, $a->productId] <=> [$a->confidence, $b->productId]);

        foreach ($ordered as $verdict) {
            $this->verdictRow($run, $verdict, ++$rank, null, null);
        }
    }

    /**
     * The attempts=0 unconsume path (§10): a transient provider failure
     * BEFORE anything was billed deletes the pending row, so the anchor
     * stays unconsumed and the retry/sweep is free by construction. Billed
     * rows are NEVER deleted — the ledger is authoritative; tries-exhausted
     * billed rows finalize as skipped_provider instead (the job's rule).
     */
    public function deleteUnbilled(VlmVerificationRun $run): void
    {
        if ($run->outcome !== VlmRunOutcome::Pending || $run->attempts !== 0) {
            throw new InvalidArgumentException('Only a pending run with zero billed attempts may be deleted.');
        }

        $run->delete();
    }

    /**
     * DEF-021 discovery rows (§4): shipped, in-window posts whose visual
     * outcome is missing or skipped get an 'unverifiable' run row — never
     * sent to Gemini (zero frames = nothing to look at). Anchor null;
     * deduped per (owner, trigger_reason) where the anchor is null, so the
     * daily sweep can never duplicate them. Priority is always MEDIUM: no
     * candidates were resolved, so no HIGH claim exists.
     */
    public function recordUnverifiable(ContentItem|Story $target, VlmTriggerReason $reason,
        string $modelVersion, string $correlationId): ?VlmVerificationRun
    {
        if (! in_array($reason, [VlmTriggerReason::UnverifiableNoRun, VlmTriggerReason::UnverifiableSkippedRun], true)) {
            throw new InvalidArgumentException('Unverifiable rows carry an unverifiable:* trigger reason.');
        }

        $ownerColumn = $target instanceof ContentItem ? 'content_item_id' : 'story_id';

        $exists = VlmVerificationRun::query()
            ->where($ownerColumn, $target->id)
            ->whereNull('visual_match_run_id')
            ->where('trigger_reason', $reason->value)
            ->exists();

        if ($exists) {
            return null;
        }

        try {
            return VlmVerificationRun::query()->create([
                $ownerColumn => $target->id,
                'visual_match_run_id' => null,
                'correlation_id' => $correlationId,
                'model_version' => $modelVersion,
                'trigger_reason' => $reason->value,
                'priority' => Priority::Medium->value,
                'frames_sent' => 0,
                'attempts' => 0,
                'outcome' => VlmRunOutcome::Unverifiable->value,
                'thresholds' => $this->thresholdSnapshot(),
                'latency_ms' => 0,
                'estimated_cost_micro_usd' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent sweep landed it first — the partial unique
            // (owner, trigger_reason) WHERE anchor IS NULL is the backstop.
            return null;
        }
    }

    /**
     * Consumption bookkeeping (§4): a TERMINAL row for (anchor, model)
     * means this anchor was verified at this model version. Pending rows
     * do NOT consume (they are resumable ledgers) and deferrable skips
     * wrote no row at all — both stay sweep-eligible.
     */
    public function terminalRunExists(?VisualMatchRun $anchor, string $modelVersion): bool
    {
        if ($anchor === null) {
            return false;
        }

        return VlmVerificationRun::query()
            ->where('visual_match_run_id', $anchor->id)
            ->where('model_version', $modelVersion)
            ->where('outcome', '!=', VlmRunOutcome::Pending->value)
            ->exists();
    }

    private function verdictRow(VlmVerificationRun $run, CandidateVerdict $verdict, int $rank, ?string $band, ?string $rejectionReason): void
    {
        // Labels denormalized at write time so the audit survives later
        // catalog edits (spec §8.2). A candidate whose catalog row vanished
        // mid-flight keeps its verdict under a fallback label.
        $product = Product::query()->with('brand')->find($verdict->productId);

        VlmCandidateVerdict::query()->create([
            'vlm_verification_run_id' => $run->id,
            'product_id' => $product?->id,
            'product_label' => $product?->name ?? $verdict->productKey,
            'brand_label' => $product?->brand?->name ?? 'unknown',
            'rank' => $rank,
            'visible' => $verdict->visible,
            'spoken' => $verdict->spoken,
            'gifting_cue' => $verdict->giftingCue,
            'confidence' => round($verdict->confidence, 4),
            'frame_timestamps' => $verdict->frameTimestampsMs,
            'rationale' => $verdict->rationale,
            'band' => $band,
            'rejection_reason' => $this->truncate($rejectionReason),
        ]);
    }

    /**
     * Model-supplied reason strings can exceed the varchar(100)
     * rejection_reason columns — truncate to characters (not bytes) BEFORE
     * persisting so an oversized reason can never abort a finalize.
     */
    private function truncate(?string $reason): ?string
    {
        return $reason === null ? null : mb_substr($reason, 0, self::REASON_MAX_CHARS);
    }

    /** @return array{auto: float, review: float, margin: float} */
    private function thresholdSnapshot(): array
    {
        $config = (array) config('qds.enrichment.vlm.thresholds', []);

        return [
            'auto' => (float) ($config['auto'] ?? 0.85),
            'review' => (float) ($config['review'] ?? 0.60),
            'margin' => (float) ($config['margin'] ?? 0.10),
        ];
    }
}
