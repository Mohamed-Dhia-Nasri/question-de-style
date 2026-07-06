<?php

namespace App\Shared\ValueObjects;

use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\VerificationStatus;
use InvalidArgumentException;

/**
 * The ConfidenceAssessment envelope — required on every inferred/estimated
 * value (location, authenticity, organic-vs-paid classification, sector).
 *
 * Shape canonical in docs/30-data-model/00-data-model.md#envelopes; doctrine
 * in DP-003 / DP-004 / ADR-0008. AI-produced values start at AI_ASSESSED and
 * move along ENUM-VerificationStatus through the human-in-the-loop review.
 */
final readonly class ConfidenceAssessment
{
    public function __construct(
        /** The inferred value the assessment qualifies (label, score, …). */
        public mixed $value,
        public ConfidenceLevel $confidenceLevel,
        /** @var list<string> contributing signals (feeds human review, DP-004) */
        public array $signals,
        public VerificationStatus $verificationStatus,
    ) {
        if ($this->signals === []) {
            throw new InvalidArgumentException(
                'ConfidenceAssessment.signals must not be empty (DP-003): record the signals behind the inference.'
            );
        }
    }

    /** Whether a human should review before this value is acted on (DP-004). */
    public function needsHumanReview(): bool
    {
        return $this->verificationStatus === VerificationStatus::AiAssessed
            && in_array($this->confidenceLevel, [ConfidenceLevel::Low, ConfidenceLevel::Unknown], true);
    }

    /** @return array{value: mixed, confidenceLevel: string, signals: list<string>, verificationStatus: string} */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'confidenceLevel' => $this->confidenceLevel->value,
            'signals' => $this->signals,
            'verificationStatus' => $this->verificationStatus->value,
        ];
    }

    /** @param array{value: mixed, confidenceLevel: string, signals: list<string>, verificationStatus: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            value: $data['value'],
            confidenceLevel: ConfidenceLevel::from($data['confidenceLevel']),
            signals: $data['signals'],
            verificationStatus: VerificationStatus::from($data['verificationStatus']),
        );
    }
}
