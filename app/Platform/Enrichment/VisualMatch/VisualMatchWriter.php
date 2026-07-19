<?php

namespace App\Platform\Enrichment\VisualMatch;

use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Keyframes\KeyframeRepository;
use App\Platform\Enrichment\Support\HumanPrecedence;
use App\Platform\Enrichment\VisualMatch\Matching\BandResult;
use App\Platform\Enrichment\VisualMatch\Matching\ThresholdResolver;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\RecognitionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\Enums\VisualMatchBand;
use App\Shared\ValueObjects\ConfidenceAssessment;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * DP-004-aware writer for VISUAL_PRODUCT detections (sub-project C),
 * mirroring TextSignalRecognizer::upsert: identity (target, VISUAL_PRODUCT,
 * 'visual-product:<productId>'), human-touched rows never overwritten,
 * identity-adjacent fields (brand/product/product_id) seeded only on
 * create, unique-violation recovery. AUTO writes HIGH; REVIEW writes LOW
 * (queues for humans). REJECT never writes a row — an EXISTING AI row
 * whose support vanished is downgraded via withdrawSupport, never deleted.
 */
final class VisualMatchWriter
{
    private const SOURCE_VERSION = 'visual-match-v1';

    private const WITHDRAWN_SIGNAL = 'visual-support-withdrawn';

    public function __construct(
        private readonly KeyframeRepository $keyframes,
        private readonly ThresholdResolver $thresholds,
    ) {}

    /**
     * Only called for AUTO/REVIEW bands (the matcher routes REJECT to
     * withdrawSupport).
     *
     * @return int 1 if the row was written/updated, 0 if a human decision blocked it
     */
    public function write(ContentItem|Story $target, BandResult $result, string $modelVersion): int
    {
        $identity = [
            $target instanceof ContentItem ? 'content_item_id' : 'story_id' => $target->id,
            'recognition_type' => RecognitionType::VisualProduct,
            'provider_label' => 'visual-product:'.$result->candidate->productId,
        ];

        $detection = RecognitionDetection::query()->firstOrNew($identity);

        if ($detection->exists && ! HumanPrecedence::allowsAiUpdate($detection->assessment)) {
            return 0;
        }

        if (! $detection->exists) {
            // Identity-adjacent fields seed on first insert only — a human
            // correction of brand/product survives later AI re-runs (DP-004).
            $detection->detected_brand = $result->candidate->brandName;
            $detection->detected_product = $result->candidate->productLabel;
            $detection->product_id = $result->candidate->productId;
        }

        $detection->fill([
            'detected_text' => null,
            'assessment' => new ConfidenceAssessment(
                // Value = brand name (review-correction contract: correction
                // is brand-only today; follows the row's brand).
                value: $detection->detected_brand ?? $result->candidate->brandName,
                confidenceLevel: $result->band === VisualMatchBand::Auto ? ConfidenceLevel::High : ConfidenceLevel::Low,
                signals: $this->signals($target, $result, $modelVersion),
                verificationStatus: VerificationStatus::AiAssessed,
            ),
            'provenance' => new Provenance(SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS, CarbonImmutable::now(), self::SOURCE_VERSION),
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
     * Catalog/model drift: an earlier AI VISUAL_PRODUCT row whose candidate
     * now rejects is downgraded to LOW + 'visual-support-withdrawn' (routes
     * to review; humans decide). Human-touched rows stay untouched (DP-004).
     *
     * @return int 1 if an AI row was downgraded, 0 otherwise
     */
    public function withdrawSupport(ContentItem|Story $target, int $productId): int
    {
        $detection = RecognitionDetection::query()
            ->where($target instanceof ContentItem ? 'content_item_id' : 'story_id', $target->id)
            ->where('recognition_type', RecognitionType::VisualProduct)
            ->where('provider_label', 'visual-product:'.$productId)
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
            'provenance' => new Provenance(SourceRegistry::GOOGLE_GEMINI_EMBEDDINGS, CarbonImmutable::now(), self::SOURCE_VERSION),
        ]);

        $detection->save();

        return 1;
    }

    /** @return non-empty-list<string> the frozen, review-UI-visible signal trail */
    private function signals(ContentItem|Story $target, BandResult $result, string $modelVersion): array
    {
        // Total = stored keyframes for the target, read through the
        // B-contract repository only (C never touches the keyframes table).
        $total = count($this->keyframes->forOwner($target)->frames);

        $signals = [
            'visual-product-match:'.$result->candidate->productLabel,
            sprintf('visual-frames-supporting:%d/%d', $result->supportCount, $total),
        ];

        // Per supporting frame, first 5. Null-timestamp frames (carousel/
        // story images) carry no t= evidence — the aggregate line covers them.
        foreach (array_slice($result->supportingFrames, 0, 5) as $frame) {
            if ($frame->timestampMs !== null) {
                $signals[] = sprintf('visual-frame:t=%dms:sim=%.2f', $frame->timestampMs, $frame->similarity);
            }
        }

        $thresholds = $this->thresholds->for($result->candidate->category);
        $signals[] = sprintf(
            'visual-threshold:%s:auto=%.2f:review=%.2f:margin=%.2f',
            $result->candidate->category?->value ?? 'default',
            $thresholds->auto,
            $thresholds->review,
            $thresholds->margin,
        );

        $signals[] = 'embedding-model:'.$modelVersion;

        return $signals;
    }
}
