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
    ) {}
}
