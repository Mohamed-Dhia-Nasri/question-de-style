<?php

namespace App\Platform\Enrichment\Recognition;

use App\Shared\Enums\RecognitionType;

/**
 * One normalized recognition signal from an AI provider, before it becomes
 * an ENT-RecognitionDetection row: the canonical recognition type, the
 * evidence text, the (possibly brand-matched) label, and the provider's
 * numeric confidence where it supplied one.
 */
final readonly class RecognitionCandidate
{
    public function __construct(
        public RecognitionType $type,
        public ?string $detectedText,
        public ?string $detectedBrand,
        public ?float $score,
        /** @var list<string> evidence descriptors for the ConfidenceAssessment */
        public array $signals,
        /**
         * The untouched provider label BEFORE lexicon mapping. This is the
         * detection's stable identity — two distinct raw labels that map to
         * one brand must remain two detections (M27). detectedBrand is the
         * mapped, human-correctable value.
         */
        public ?string $providerLabel = null,
    ) {}
}
