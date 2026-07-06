<?php

namespace App\Platform\Ingestion\DTO;

/**
 * Result of fetching + validating + normalizing one provider call:
 * the accepted, documented DTOs plus every rejected raw record (bound for
 * quarantine) and the timing/observability metadata the recorder persists.
 */
final readonly class NormalizedBatch
{
    public function __construct(
        /**
         * Accepted, documented DTOs (ingestion: ProfileData / ContentData /
         * StoryData; enrichment: RecognitionCandidate).
         *
         * @var list<object>
         */
        public array $items,
        /** @var list<RejectedRecord> invalid records bound for quarantine */
        public array $rejected,
        public ProviderResponse $response,
        /** Milliseconds spent on response-envelope validation. */
        public float $validationMs,
        /** Milliseconds spent on per-item normalization. */
        public float $normalizationMs,
    ) {}
}
