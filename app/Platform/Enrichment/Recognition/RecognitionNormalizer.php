<?php

namespace App\Platform\Enrichment\Recognition;

use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Platform\Ingestion\DTO\ProviderResponse;
use App\Platform\Ingestion\DTO\RejectedRecord;
use App\Platform\Ingestion\Support\ErrorCategory;
use App\Shared\Enums\RecognitionType;

/**
 * Normalizes raw Google AI responses into canonical RecognitionCandidates
 * (validated before persistence — invalid items are rejected for
 * quarantine, never silently stored).
 *
 * Storage policy (canonical: ENT-RecognitionDetection is "a brand-
 * recognition hit"):
 *  - a LOGO annotation is inherently a brand claim → always a candidate;
 *  - OCR / transcript / on-screen text yields a candidate ONLY when a
 *    known brand (name/alias) appears in the text — free text with no
 *    brand signal is not a recognition hit and is not stored;
 *  - a general brand signal is never narrowed to a specific product.
 */
class RecognitionNormalizer
{
    private const MAX_TEXT_LENGTH = 2000;

    public function __construct(private readonly BrandLexicon $lexicon) {}

    /** Vision images:annotate → IMAGE_TEXT_OCR + LOGO candidates. */
    public function visionBatch(ProviderResponse $response): NormalizedBatch
    {
        $start = microtime(true);

        $items = [];
        $rejected = [];

        foreach ($response->items as $annotationSet) {
            if (! is_array($annotationSet)) {
                $rejected[] = new RejectedRecord(ErrorCategory::InvalidFieldTypes, 'Annotation response is not an object.', ['value' => $annotationSet]);

                continue;
            }

            if (isset($annotationSet['error'])) {
                $status = $annotationSet['error']['status'] ?? 'unknown';
                $rejected[] = new RejectedRecord(
                    ErrorCategory::UpstreamError,
                    'Vision annotation failed for one image (status: '.(is_string($status) ? $status : 'unknown').').',
                    $annotationSet,
                );

                continue;
            }

            foreach ((array) ($annotationSet['logoAnnotations'] ?? []) as $logo) {
                if (! is_array($logo) || ! is_string($logo['description'] ?? null) || trim($logo['description']) === '') {
                    $rejected[] = new RejectedRecord(ErrorCategory::MissingRequiredFields, 'Logo annotation without a description.', is_array($logo) ? $logo : ['value' => $logo]);

                    continue;
                }

                $score = $logo['score'] ?? null;

                if ($score !== null && ! is_numeric($score)) {
                    $rejected[] = new RejectedRecord(ErrorCategory::InvalidFieldTypes, 'Logo annotation with a non-numeric score.', $logo);

                    continue;
                }

                $label = trim($logo['description']);
                $brand = $this->lexicon->matchLabel($label);

                $items[] = new RecognitionCandidate(
                    type: RecognitionType::Logo,
                    detectedText: null,
                    detectedBrand: $brand ?? $label,
                    providerLabel: $label,
                    score: $score !== null ? (float) $score : null,
                    signals: [
                        $score !== null ? sprintf('logo-match-score:%.2f', (float) $score) : 'logo-match-score:unavailable',
                        $brand !== null ? 'brand-lexicon:matched' : 'brand-lexicon:unmatched',
                    ],
                );
            }

            $fullText = $annotationSet['textAnnotations'][0]['description'] ?? null;

            if (is_string($fullText) && trim($fullText) !== '') {
                $candidate = $this->textCandidate(RecognitionType::ImageTextOcr, $fullText, null, 'ocr-brand-text-match');

                if ($candidate !== null) {
                    $items[] = $candidate;
                }
            }
        }

        return new NormalizedBatch(
            items: $items,
            rejected: $rejected,
            response: $response,
            validationMs: 0.0,
            normalizationMs: (microtime(true) - $start) * 1000,
        );
    }

    /** Speech-to-Text results → SPOKEN_BRAND candidates. */
    public function speechBatch(ProviderResponse $response): NormalizedBatch
    {
        $start = microtime(true);

        $items = [];
        $rejected = [];

        foreach ($response->items as $result) {
            if (! is_array($result)) {
                $rejected[] = new RejectedRecord(ErrorCategory::InvalidFieldTypes, 'Speech result is not an object.', ['value' => $result]);

                continue;
            }

            $alternative = $result['alternatives'][0] ?? null;
            $transcript = is_array($alternative) ? ($alternative['transcript'] ?? null) : null;

            if (! is_string($transcript) || trim($transcript) === '') {
                $rejected[] = new RejectedRecord(ErrorCategory::MissingRequiredFields, 'Speech result without a transcript.', $result);

                continue;
            }

            $confidence = is_numeric($alternative['confidence'] ?? null)
                ? (float) $alternative['confidence']
                : null;

            $candidate = $this->textCandidate(RecognitionType::SpokenBrand, $transcript, $confidence, 'spoken-brand-transcript-match');

            if ($candidate !== null) {
                $items[] = $candidate;
            }
        }

        return new NormalizedBatch(
            items: $items,
            rejected: $rejected,
            response: $response,
            validationMs: 0.0,
            normalizationMs: (microtime(true) - $start) * 1000,
        );
    }

    /**
     * A stored provider transcript → SPOKEN_BRAND candidates (sub-project B:
     * YouTube rides the transcript stage since its audio is not downloadable
     * in-freeze). Same lexicon gate as speechBatch — free text with no known
     * brand is not a recognition hit. The synthetic response describes this
     * LOCAL normalization pass (no provider call happens here).
     */
    public function transcriptBatch(string $transcript): NormalizedBatch
    {
        $start = microtime(true);

        $items = [];
        $candidate = $this->textCandidate(RecognitionType::SpokenBrand, $transcript, null, 'spoken-brand-transcript-match');

        if ($candidate !== null) {
            $items[] = $candidate;
        }

        return new NormalizedBatch(
            items: $items,
            rejected: [],
            response: new ProviderResponse(
                items: [],
                httpStatus: 200,
                responseBytes: 0,
                requestMs: 0.0,
                sourceVersion: 'youtube-transcript-v1',
            ),
            validationMs: 0.0,
            normalizationMs: (microtime(true) - $start) * 1000,
        );
    }

    /** Video Intelligence annotationResults → ON_SCREEN_TEXT + LOGO candidates. */
    public function videoBatch(ProviderResponse $response): NormalizedBatch
    {
        $start = microtime(true);

        $items = [];
        $rejected = [];

        foreach ($response->items as $result) {
            if (! is_array($result)) {
                $rejected[] = new RejectedRecord(ErrorCategory::InvalidFieldTypes, 'Video annotation result is not an object.', ['value' => $result]);

                continue;
            }

            foreach ((array) ($result['textAnnotations'] ?? []) as $text) {
                $value = is_array($text) ? ($text['text'] ?? null) : null;

                if (! is_string($value) || trim($value) === '') {
                    continue;
                }

                $confidence = is_numeric($text['segments'][0]['confidence'] ?? null)
                    ? (float) $text['segments'][0]['confidence']
                    : null;

                $candidate = $this->textCandidate(RecognitionType::OnScreenText, $value, $confidence, 'on-screen-text-match');

                if ($candidate !== null) {
                    $items[] = $candidate;
                }
            }

            foreach ((array) ($result['logoRecognitionAnnotations'] ?? []) as $logo) {
                $label = is_array($logo) ? ($logo['entity']['description'] ?? null) : null;

                if (! is_string($label) || trim($label) === '') {
                    $rejected[] = new RejectedRecord(ErrorCategory::MissingRequiredFields, 'Video logo annotation without an entity description.', is_array($logo) ? $logo : ['value' => $logo]);

                    continue;
                }

                $confidence = is_numeric($logo['tracks'][0]['confidence'] ?? null)
                    ? (float) $logo['tracks'][0]['confidence']
                    : null;

                $brand = $this->lexicon->matchLabel(trim($label));

                $items[] = new RecognitionCandidate(
                    type: RecognitionType::Logo,
                    detectedText: null,
                    detectedBrand: $brand ?? trim($label),
                    providerLabel: trim($label),
                    score: $confidence,
                    signals: [
                        $confidence !== null ? sprintf('logo-match-score:%.2f', $confidence) : 'logo-match-score:unavailable',
                        $brand !== null ? 'brand-lexicon:matched' : 'brand-lexicon:unmatched',
                    ],
                );
            }
        }

        return new NormalizedBatch(
            items: $items,
            rejected: $rejected,
            response: $response,
            validationMs: 0.0,
            normalizationMs: (microtime(true) - $start) * 1000,
        );
    }

    private function textCandidate(RecognitionType $type, string $text, ?float $score, string $matchSignal): ?RecognitionCandidate
    {
        $brand = $this->lexicon->matchInText($text);

        if ($brand === null) {
            // Free text without a known brand is not a recognition hit.
            return null;
        }

        return new RecognitionCandidate(
            type: $type,
            detectedText: mb_substr(trim($text), 0, self::MAX_TEXT_LENGTH),
            detectedBrand: $brand,
            // provider_label is varchar(255) while detected_text allows more.
            providerLabel: mb_substr(trim($text), 0, 255),
            score: $score,
            signals: [
                $matchSignal.':'.$brand,
                $score !== null ? sprintf('provider-confidence:%.2f', $score) : 'provider-confidence:unavailable',
            ],
        );
    }
}
