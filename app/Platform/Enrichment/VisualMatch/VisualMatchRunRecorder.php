<?php

namespace App\Platform\Enrichment\VisualMatch;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Models\VisualMatchCandidate;
use App\Modules\Monitoring\Models\VisualMatchRun;
use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateSet;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparationResult;
use App\Platform\Enrichment\VisualMatch\Matching\BandResult;
use App\Platform\Enrichment\VisualMatch\Matching\FrameScore;
use App\Platform\Enrichment\VisualMatch\Matching\ThresholdResolver;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\Enums\VisualMatchOutcome;
use InvalidArgumentException;

/**
 * Persists the append-only audit trail of one visual-match analysis run: a
 * visual_match_runs row (always, once candidates were known — skips
 * included), plus ranked visual_match_candidates rows on completed runs.
 * The latest run per post is authoritative; history feeds calibration (E)
 * and sub-project D's needs_verification poll.
 */
final class VisualMatchRunRecorder
{
    private const REASON_NO_EMBEDDED_PHOTOS = 'no-embedded-photos';

    public function __construct(private readonly ThresholdResolver $thresholds) {}

    /** @param list<BandResult> $results ranked best-first (BandMapper order) */
    public function record(ContentItem|Story $target, string $correlationId, CandidateSet $candidates,
        FramePreparationResult $prep, array $results, VisualMatchOutcome $outcome,
        string $modelVersion, int $billedCalls, int $cacheHits, int $processingMs, bool $needsVerification): VisualMatchRun
    {
        // Boundary guard (house pattern precedent: ConfidenceAssessment's
        // constructor throwing on empty signals): a skipped run assessed
        // nothing, so it can never carry candidate verdicts or a
        // verification poll flag. Enforced here — not left to caller
        // discipline — and BEFORE the run row is inserted, so a violation
        // never lands a half-recorded run.
        $skipped = in_array($outcome, [
            VisualMatchOutcome::SkippedBudget, VisualMatchOutcome::SkippedReadOnly, VisualMatchOutcome::SkippedProvider,
        ], true);

        if ($skipped && ($results !== [] || $needsVerification)) {
            throw new InvalidArgumentException('A skipped run records no candidate verdicts and never needs verification.');
        }

        $bestScore = null;

        foreach ($results as $result) {
            $bestScore = max($bestScore ?? 0.0, $result->bestSimilarity);
        }

        $run = VisualMatchRun::query()->create([
            $target instanceof ContentItem ? 'content_item_id' : 'story_id' => $target->id,
            'correlation_id' => $correlationId,
            'model_version' => $modelVersion,
            'priority' => $candidates->priority->value,
            'frames_available' => $prep->framesAvailable,
            'frames_processed' => $billedCalls + $cacheHits,
            'frames_skipped_format' => $prep->skippedFormat,
            'frames_skipped_quality' => $prep->skippedQuality,
            'frames_deduped' => $prep->deduped,
            'cache_hits' => $cacheHits,
            'processing_ms' => $processingMs,
            'candidates_checked' => count($candidates->candidates),
            'best_score' => $bestScore !== null ? round($bestScore, 4) : null,
            'outcome' => $outcome->value,
            'rejection_reason' => $this->runRejectionReason($results, $outcome),
            'thresholds' => $this->thresholdSnapshot($candidates),
            'embedding_calls' => $billedCalls,
            'estimated_cost_micro_usd' => $billedCalls * (int) config('qds.ai_budget.capabilities.embedding.price_micro_usd_per_unit'),
            'needs_verification' => $needsVerification,
        ]);

        $rank = 0;

        foreach ($results as $result) {
            $this->candidateRow($run, $result->candidate, ++$rank, [
                'band' => $result->band->value,
                'best_similarity' => round($result->bestSimilarity, 4),
                'margin_to_runner_up' => $result->marginToRunnerUp !== null ? round($result->marginToRunnerUp, 4) : null,
                'supporting_frames' => array_map(static fn (FrameScore $f): array => [
                    'ordinal' => $f->ordinal,
                    'timestamp_ms' => $f->timestampMs,
                    'similarity' => round($f->similarity, 4),
                    'photo_id' => $f->photoId,
                    'represented_frames' => $f->representedFrames,
                ], $result->supportingFrames),
                'rejection_reason' => $result->rejectionReason,
                'first_support_ms' => $result->firstSupportMs,
                'last_support_ms' => $result->lastSupportMs,
                'estimated_visible_ms' => $result->estimatedVisibleMs,
            ]);
        }

        // Unmatchable candidates (no embedded reference photos) are recorded
        // on COMPLETED runs only — coverage accounting for D and reviewers.
        // Skipped runs assessed nothing; no per-candidate verdicts there
        // ($skipped computed once, in the guard above).
        if (! $skipped) {
            foreach ($candidates->candidates as $candidate) {
                if ($candidate->hasEmbeddedPhotos) {
                    continue;
                }

                $this->candidateRow($run, $candidate, ++$rank, [
                    'band' => VisualMatchBand::Reject->value,
                    'best_similarity' => 0,
                    'margin_to_runner_up' => null,
                    'supporting_frames' => [],
                    'rejection_reason' => self::REASON_NO_EMBEDDED_PHOTOS,
                    'first_support_ms' => null,
                    'last_support_ms' => null,
                    'estimated_visible_ms' => null,
                ]);
            }
        }

        return $run;
    }

    /** @param array<string, mixed> $verdict */
    private function candidateRow(VisualMatchRun $run, Candidate $candidate, int $rank, array $verdict): void
    {
        VisualMatchCandidate::query()->create([
            'visual_match_run_id' => $run->id,
            'product_id' => $candidate->productId,
            'product_label' => $candidate->productLabel,
            'category' => $candidate->category?->value,
            'rank' => $rank,
            'source' => $candidate->source,
            'shipment_in_window' => $candidate->shipmentInWindow,
            'seeding_campaign_id' => $candidate->seedingCampaignId,
            'shipment_anchor_at' => $candidate->shipmentAnchorAt,
            'shipment_age_days' => $candidate->shipmentAgeDays,
            ...$verdict,
        ]);
    }

    /** @param list<BandResult> $results */
    private function runRejectionReason(array $results, VisualMatchOutcome $outcome): ?string
    {
        if (! in_array($outcome, [VisualMatchOutcome::NoMatch, VisualMatchOutcome::Inconclusive], true)) {
            return null;
        }

        return $results[0]->rejectionReason ?? null;
    }

    /** @return array<string, array{auto: float, review: float, margin: float}> snapshot per candidate category */
    private function thresholdSnapshot(CandidateSet $candidates): array
    {
        $snapshot = [];

        foreach ($candidates->candidates as $candidate) {
            $key = $candidate->category?->value ?? 'default';

            if (isset($snapshot[$key])) {
                continue;
            }

            $thresholds = $this->thresholds->for($candidate->category);
            $snapshot[$key] = ['auto' => $thresholds->auto, 'review' => $thresholds->review, 'margin' => $thresholds->margin];
        }

        // Always snapshot the default map too (run-level debugging aid).
        if (! isset($snapshot['default'])) {
            $thresholds = $this->thresholds->for(null);
            $snapshot['default'] = ['auto' => $thresholds->auto, 'review' => $thresholds->review, 'margin' => $thresholds->margin];
        }

        return $snapshot;
    }
}
