<?php

namespace App\Platform\Enrichment\VisualMatch\Candidates;

use App\Shared\Enums\SectorLabel;
use Carbon\CarbonImmutable;

/**
 * One product plausibly visible in a post, with the evidence of WHY it was
 * considered (candidate-source columns of visual_match_candidates, spec
 * §4.5). Only candidates with ≥ 1 embedded reference photo at the
 * configured model_version are matchable; the rest are recorded for
 * coverage accounting and cost nothing.
 */
final readonly class Candidate
{
    public function __construct(
        public int $productId,
        public string $productLabel,
        public string $brandName,
        public ?SectorLabel $category,
        public string $source,               // 'shipment'|'roster'
        public bool $shipmentInWindow,
        public ?int $seedingCampaignId,
        public ?CarbonImmutable $shipmentAnchorAt,
        public ?int $shipmentAgeDays,        // anchor → post published_at, whole days
        public bool $hasEmbeddedPhotos,
    ) {}
}
