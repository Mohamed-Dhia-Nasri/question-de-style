<?php

namespace App\Platform\Enrichment\VisualMatch\Candidates;

use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\CRM\Models\Shipment;
use App\Modules\CRM\Services\ActiveSeedingCreatorIds;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\AiBudget\Priority;
use App\Shared\Settings\MonitoringSettingsResolver;
use Carbon\CarbonImmutable;

/**
 * Resolves which products could plausibly appear in a post (spec §7):
 *  - shipment candidates: the creator's dispatched shipments whose
 *    attribution window [anchor = delivered ?? shipped, anchor + tenant
 *    shipment_window_days] contains the post's publish/capture time —
 *    byte-identical semantics to MentionClassifier::timingSatisfied
 *    (ADR-0025, both edges inclusive), zero new knobs;
 *  - roster candidates: primary products of ACTIVE/SHIPPING seeding
 *    campaigns whose roster contains the creator.
 * Union, deduped (shipment evidence wins), tenant-scoped through the
 * models' BelongsToTenant under the enrichment job's TenantContext.
 * An empty set costs nothing — the tiering that keeps most posts free.
 */
final class CandidateScope
{
    public function __construct(private readonly MonitoringSettingsResolver $settings) {}

    public function forTarget(ContentItem|Story $target): CandidateSet
    {
        $creatorId = $target->platformAccount?->creator_id;
        $publishedAt = $target instanceof ContentItem ? $target->published_at : $target->captured_at;

        if ($creatorId === null || $publishedAt === null) {
            // The matcher reports skipped:no-creator before priority matters.
            return new CandidateSet([], Priority::Medium);
        }

        $windowDays = $this->settings->shipmentWindowDays();

        /** @var array<int, array{product: Product, anchor: CarbonImmutable, ageDays: int, campaignId: int|null, campaignActive: bool}> $shipmentRows */
        $shipmentRows = [];

        $shipments = Shipment::query()
            ->where('creator_id', $creatorId)
            ->whereNotNull('shipped_at')
            ->with(['seedingCampaign', 'product.brand'])
            ->orderBy('id')
            ->get();

        foreach ($shipments as $shipment) {
            /** @var CarbonImmutable $anchor delivery beats shipping (ShipmentEvidence::anchorDate) */
            $anchor = $shipment->delivered_at ?? $shipment->shipped_at;

            $inWindow = $publishedAt->greaterThanOrEqualTo($anchor)
                && $publishedAt->lessThanOrEqualTo($anchor->addDays($windowDays));

            if (! $inWindow) {
                continue;
            }

            $existing = $shipmentRows[$shipment->product_id] ?? null;

            // One candidate per product: the freshest anchor is the evidence
            // stamp; equal anchors keep the lower shipment id (determinism).
            if ($existing !== null && ! $anchor->greaterThan($existing['anchor'])) {
                continue;
            }

            $shipmentRows[$shipment->product_id] = [
                'product' => $shipment->product,
                'anchor' => $anchor,
                'ageDays' => (int) floor($anchor->diffInDays($publishedAt)),
                'campaignId' => $shipment->seeding_campaign_id,
                'campaignActive' => in_array($shipment->seedingCampaign->status, ActiveSeedingCreatorIds::ACTIVE_STATUSES, true),
            ];
        }

        /** @var array<int, array{product: Product, campaignId: int}> $rosterRows */
        $rosterRows = [];

        $campaigns = SeedingCampaign::query()
            ->whereIn('status', ActiveSeedingCreatorIds::statusValues())
            ->whereNotNull('product_id')
            ->whereHas('creators', fn ($query) => $query->whereKey($creatorId))
            ->with('product.brand')
            ->orderBy('id')
            ->get();

        foreach ($campaigns as $campaign) {
            $productId = (int) $campaign->product_id;

            if (isset($shipmentRows[$productId]) || isset($rosterRows[$productId])) {
                continue;
            }

            $rosterRows[$productId] = ['product' => $campaign->product, 'campaignId' => $campaign->id];
        }

        if ($shipmentRows === [] && $rosterRows === []) {
            return new CandidateSet([], Priority::Medium);
        }

        ksort($shipmentRows);
        ksort($rosterRows);

        $embedded = $this->productsWithEmbeddedPhotos([...array_keys($shipmentRows), ...array_keys($rosterRows)]);
        $candidates = [];

        foreach ($shipmentRows as $productId => $row) {
            $candidates[] = new Candidate(
                productId: $productId,
                productLabel: $row['product']->name,
                brandName: $row['product']->brand->name,
                category: $row['product']->category,
                source: 'shipment',
                shipmentInWindow: true,
                seedingCampaignId: $row['campaignId'],
                shipmentAnchorAt: $row['anchor'],
                shipmentAgeDays: $row['ageDays'],
                hasEmbeddedPhotos: in_array($productId, $embedded, true),
            );
        }

        foreach ($rosterRows as $productId => $row) {
            $candidates[] = new Candidate(
                productId: $productId,
                productLabel: $row['product']->name,
                brandName: $row['product']->brand->name,
                category: $row['product']->category,
                source: 'roster',
                shipmentInWindow: false,
                seedingCampaignId: $row['campaignId'],
                shipmentAnchorAt: null,
                shipmentAgeDays: null,
                hasEmbeddedPhotos: in_array($productId, $embedded, true),
            );
        }

        // HIGH: any active-campaign link (roster by construction, or a
        // shipment whose campaign is ACTIVE/SHIPPING). MEDIUM: shipments
        // outside active campaigns only (spec §7 priority tiers).
        $priority = $rosterRows !== []
            || array_filter($shipmentRows, fn (array $row): bool => $row['campaignActive']) !== []
            ? Priority::High
            : Priority::Medium;

        return new CandidateSet($candidates, $priority);
    }

    /**
     * Products with ≥ 1 embedded reference photo at the configured
     * model_version — the matchability gate (spec §7). Tenant isolation
     * rides on ProductReferencePhoto's qualified TenantScope.
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    private function productsWithEmbeddedPhotos(array $productIds): array
    {
        $modelVersion = (string) config('qds.enrichment.visual_match.model_version');

        return ProductReferencePhoto::query()
            ->whereIn('product_reference_photos.product_id', $productIds)
            ->join(
                'product_photo_embeddings',
                'product_photo_embeddings.product_reference_photo_id',
                '=',
                'product_reference_photos.id',
            )
            ->where('product_photo_embeddings.model_version', $modelVersion)
            ->distinct()
            ->pluck('product_reference_photos.product_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
