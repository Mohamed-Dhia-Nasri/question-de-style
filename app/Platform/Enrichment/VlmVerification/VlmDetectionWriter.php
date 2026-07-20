<?php

namespace App\Platform\Enrichment\VlmVerification;

use App\Modules\CRM\Models\Product;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Support\HumanPrecedence;
use App\Platform\Enrichment\VlmVerification\Banding\VlmBandResult;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\Enums\VlmBand;
use App\Shared\ValueObjects\ConfidenceAssessment;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * DP-004-aware writer for VLM_PRODUCT detections (sub-project D, spec §7),
 * mirroring VisualMatchWriter: identity (target, VLM_PRODUCT,
 * 'vlm-product:<productId>'), human-touched rows never overwritten,
 * identity-adjacent fields (brand/product/product_id) seeded only on
 * create, unique-violation recovery. AUTO writes HIGH; REVIEW writes LOW
 * (queues for humans). REJECT never writes a row — an EXISTING AI row
 * whose support vanished is downgraded via withdrawSupport, never deleted.
 * The 'vlm-outcome-normalized' marker is NOT a detection signal: a
 * normalized run is INCONCLUSIVE and writes no detections — the job
 * records it on the run row's rejection_reason instead.
 */
final class VlmDetectionWriter
{
    public const SOURCE_VERSION = 'vlm-verification-v1';

    private const WITHDRAWN_SIGNAL = 'vlm-support-withdrawn';

    /**
     * Only called for AUTO/REVIEW bands (the job routes REJECT to
     * withdrawSupport).
     *
     * @return int 1 if the row was written/updated, 0 if a human decision
     *             (or a vanished catalog product) blocked it
     */
    public function write(ContentItem|Story $target, VlmBandResult $result, string $modelVersion): int
    {
        $product = Product::query()->with('brand')->find($result->verdict->productId);

        if ($product === null) {
            // The catalog row vanished between the verdict and this write —
            // nothing to link (fail-closed; the verdict itself persists in
            // vlm_candidate_verdicts for the audit trail).
            return 0;
        }

        $identity = [
            $target instanceof ContentItem ? 'content_item_id' : 'story_id' => $target->id,
            'recognition_type' => RecognitionType::VlmProduct,
            'provider_label' => 'vlm-product:'.$result->verdict->productId,
        ];

        $detection = RecognitionDetection::query()->firstOrNew($identity);

        if ($detection->exists && ! HumanPrecedence::allowsAiUpdate($detection->assessment)) {
            return 0;
        }

        if (! $detection->exists) {
            // Identity-adjacent fields seed on first insert only — a human
            // correction of brand/product survives later AI re-runs (DP-004).
            $detection->detected_brand = $product->brand->name;
            $detection->detected_product = $product->name;
            $detection->product_id = $product->id;
        }

        $detection->fill([
            'detected_text' => null,
            'assessment' => new ConfidenceAssessment(
                // Value = brand name (review-correction contract: correction
                // is brand-only today; follows the row's brand).
                value: $detection->detected_brand ?? $product->brand->name,
                confidenceLevel: $result->band === VlmBand::Auto ? ConfidenceLevel::High : ConfidenceLevel::Low,
                signals: $this->signals($result, $product->name, $modelVersion),
                verificationStatus: VerificationStatus::AiAssessed,
            ),
            'provenance' => new Provenance(SourceRegistry::GOOGLE_GEMINI_VLM, CarbonImmutable::now(), self::SOURCE_VERSION),
        ]);

        try {
            $detection->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent pass inserted the same detection first (the
            // partial unique index is the backstop). Honour precedence on
            // the winning row; either way it is already recorded.
            $detection = RecognitionDetection::query()->where($identity)->firstOrFail();

            if (! HumanPrecedence::allowsAiUpdate($detection->assessment)) {
                return 0;
            }

            return 0;
        }

        return 1;
    }

    /**
     * Re-verification drift (spec §7): an earlier AI VLM_PRODUCT row whose
     * candidate now rejects is downgraded to LOW + 'vlm-support-withdrawn'
     * (routes to review; humans decide). Human-touched rows stay untouched
     * (DP-004). Never deletes.
     *
     * @return int 1 if an AI row was downgraded, 0 otherwise
     */
    public function withdrawSupport(ContentItem|Story $target, int $productId): int
    {
        $detection = RecognitionDetection::query()
            ->where($target instanceof ContentItem ? 'content_item_id' : 'story_id', $target->id)
            ->where('recognition_type', RecognitionType::VlmProduct)
            ->where('provider_label', 'vlm-product:'.$productId)
            ->first();

        if ($detection === null || ! HumanPrecedence::allowsAiUpdate($detection->assessment)) {
            return 0;
        }

        // Already withdrawn — idempotent, leave the envelope untouched.
        if ($detection->assessment->confidenceLevel === ConfidenceLevel::Low
            && in_array(self::WITHDRAWN_SIGNAL, $detection->assessment->signals, true)) {
            return 0;
        }

        $detection->fill([
            'assessment' => new ConfidenceAssessment(
                value: $detection->assessment->value,
                confidenceLevel: ConfidenceLevel::Low,
                signals: array_values(array_unique([...$detection->assessment->signals, self::WITHDRAWN_SIGNAL])),
                verificationStatus: VerificationStatus::AiAssessed,
            ),
            'provenance' => new Provenance(SourceRegistry::GOOGLE_GEMINI_VLM, CarbonImmutable::now(), self::SOURCE_VERSION),
        ]);

        $detection->save();

        return 1;
    }

    /** @return non-empty-list<string> the frozen, review-UI-visible signal trail (spec §7) */
    private function signals(VlmBandResult $result, string $productLabel, string $modelVersion): array
    {
        $verdict = $result->verdict;

        $signals = [
            'vlm-product-match:'.$productLabel,
            sprintf('vlm-confidence:%.2f', $verdict->confidence),
            'vlm-visible:'.($verdict->visible ? 'true' : 'false'),
            'vlm-spoken:'.($verdict->spoken ? 'true' : 'false'),
            'vlm-gifting-cue:'.($verdict->giftingCue ? 'true' : 'false'),
        ];

        // Per validated frame reference, first 5. Timestamps were validated
        // against the frames actually sent (§6 enum grounding) — the trail
        // can never cite a fabricated moment.
        foreach (array_slice($verdict->frameTimestampsMs, 0, 5) as $timestampMs) {
            if (is_int($timestampMs)) {
                $signals[] = sprintf('vlm-frame:t=%dms', $timestampMs);
            }
        }

        $thresholds = (array) config('qds.enrichment.vlm.thresholds', []);
        $signals[] = sprintf(
            'vlm-threshold:auto=%.2f:review=%.2f:margin=%.2f',
            (float) ($thresholds['auto'] ?? 0.85),
            (float) ($thresholds['review'] ?? 0.60),
            (float) ($thresholds['margin'] ?? 0.10),
        );

        $signals[] = 'vlm-model:'.$modelVersion;

        if ($result->captionEcho) {
            $signals[] = 'vlm-caption-echo';
        }

        return $signals;
    }
}
