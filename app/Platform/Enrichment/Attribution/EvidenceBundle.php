<?php

namespace App\Platform\Enrichment\Attribution;

use App\Shared\Enums\ConfidenceLevel;
use Carbon\CarbonImmutable;

/**
 * Everything the mention classifier may weigh for one piece of content —
 * assembled by the AttributionService, consumed by the pure
 * MentionClassifier. Nothing in here is a conclusion; each item is a
 * signal with its own strength.
 */
final readonly class EvidenceBundle
{
    public function __construct(
        /**
         * Brand-recognition evidence (non-rejected RecognitionDetections
         * carrying a normalized brand label).
         *
         * @var list<array{type: string, brand: string, level: ConfidenceLevel, productId?: int|null, product?: string|null}>
         */
        public array $recognitions = [],
        /**
         * Unambiguous, non-generic hashtag-list matches.
         *
         * @var list<array{original: string, scope: string, campaign_id: int|null, brand_id: int|null, brand_name: string|null, product_label: string|null}>
         */
        public array $hashtagMatches = [],
        /**
         * Hashtags whose match is ambiguous (multiple campaigns/brands/
         * products) — conflicting evidence, routes to review.
         *
         * @var list<string> original hashtag forms
         */
        public array $ambiguousHashtags = [],
        /**
         * Documented seeding records for this creator (Module 3).
         *
         * @var list<ShipmentEvidence>
         */
        public array $shipments = [],
        /** Platform paid-partnership disclosure label present (AC-M1-003). */
        public ?bool $paidPartnershipLabel = null,
        /** When the content was published (timing evidence). */
        public ?CarbonImmutable $publishedAt = null,
        /** @var list<string> gifting/PR cue signals (relevance booster, not a brand claim) */
        public array $contextualCues = [],
        /**
         * When true, the classifier enforces the product-aware SEEDED
         * doctrine; when false, the legacy brand-level doctrine applies.
         */
        public bool $productDoctrine = false,
    ) {}
}
