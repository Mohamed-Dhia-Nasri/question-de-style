<?php

namespace App\Platform\Enrichment\Attribution;

use App\Platform\Enrichment\Support\HashtagScope;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Settings\MonitoringSettingsResolver;

/**
 * Pure evidence-chain classifier for organic-seeding attribution
 * (REQ-M1-002, AC-M1-002/003/020, DP-003, ADR-0008).
 *
 * Doctrine encoded here:
 *  - PAID/SEEDED only with a proving record or label; the proving signal
 *    is recorded in the signals list (AC-M1-003).
 *  - A shipment alone never proves SEEDED — product/brand relevance must
 *    be independently evidenced (recognition, matched hashtag, …).
 *  - A hashtag alone never proves SEEDED — it may only strengthen a link
 *    that a documented seeding record establishes.
 *  - There is no CONFIRMED_ORGANIC: without a proving record the outcome
 *    is LIKELY_ORGANIC or UNKNOWN, never a stronger claim.
 *  - Weak, ambiguous, or conflicting evidence yields UNKNOWN at LOW/
 *    UNKNOWN confidence, which routes to human review (DP-004).
 *  - QDS works with unpaid organic seeding only: PAID is preserved for
 *    the canonical platform-disclosure-label case (AC-M1-003) and is
 *    never inferred from anything else.
 *
 * Returns null when there is no evidence of any brand/product reference —
 * content that mentions nothing creates no Mention.
 */
class MentionClassifier
{
    /**
     * One resolver per classifier instance: the resolver memoizes rows per
     * tenant id and reads the ACTIVE TenantContext on every call, so reuse
     * is tenant-safe even across runAs switches — while collapsing the
     * per-shipment settings lookups into one query per tenant.
     */
    private ?MonitoringSettingsResolver $settings = null;

    public function classify(EvidenceBundle $evidence): ?ClassificationResult
    {
        $strongRecognitions = array_values(array_filter(
            $evidence->recognitions,
            static fn (array $r): bool => in_array($r['level'], [ConfidenceLevel::High, ConfidenceLevel::Medium], true),
        ));

        $weakRecognitions = array_values(array_filter(
            $evidence->recognitions,
            static fn (array $r): bool => ! in_array($r['level'], [ConfidenceLevel::High, ConfidenceLevel::Medium], true),
        ));

        $targetedHashtags = array_values(array_filter(
            $evidence->hashtagMatches,
            static fn (array $m): bool => $m['scope'] !== HashtagScope::Agency->value,
        ));

        $agencyHashtags = array_values(array_filter(
            $evidence->hashtagMatches,
            static fn (array $m): bool => $m['scope'] === HashtagScope::Agency->value,
        ));

        $signals = $this->evidenceSignals($evidence);

        $hasRelevance = $evidence->recognitions !== [] || $targetedHashtags !== [];
        $hasAnySignal = $hasRelevance
            || $agencyHashtags !== []
            || $evidence->ambiguousHashtags !== []
            || $evidence->paidPartnershipLabel;

        if (! $hasAnySignal) {
            return null;
        }

        // A platform disclosure label is a proving record for PAID
        // (AC-M1-003). QDS's own workflow never produces paid placements,
        // so this only ever reflects the platform's own labelling.
        if ($evidence->paidPartnershipLabel) {
            return new ClassificationResult(
                MentionType::Paid,
                ConfidenceLevel::High,
                ['platform-paid-partnership-label', ...$signals],
            );
        }

        // SEEDED: a documented seeding record (link) that ALIGNS with
        // independently evidenced brand/product relevance.
        $aligned = $this->alignedShipments($evidence, $strongRecognitions, $weakRecognitions, $targetedHashtags);

        if ($aligned !== []) {
            $strongRelevance = $this->shipmentHasStrongRelevance($aligned, $strongRecognitions, $targetedHashtags);
            $timingOk = $this->timingSatisfied($aligned, $evidence);

            $shipmentSignals = array_map(
                static fn (ShipmentEvidence $s): string => $s->reference,
                $aligned,
            );

            if (! $timingOk) {
                $shipmentSignals[] = 'shipment-timing-unverified';
            }

            return new ClassificationResult(
                MentionType::Seeded,
                $strongRelevance && $timingOk ? ConfidenceLevel::High : ConfidenceLevel::Medium,
                [...$shipmentSignals, ...$signals],
            );
        }

        // Conflicting evidence: hashtags that match several campaigns/
        // brands/products cannot support any classification — a human must
        // resolve them (DP-004).
        if (! $hasRelevance && $evidence->ambiguousHashtags !== []) {
            return new ClassificationResult(
                MentionType::Unknown,
                ConfidenceLevel::Low,
                [...$signals, 'ambiguous-hashtag-match'],
            );
        }

        // Agency hashtags hint at QDS involvement but carry no brand or
        // product relevance — insufficient, stays UNKNOWN and reviews.
        if (! $hasRelevance) {
            return new ClassificationResult(
                MentionType::Unknown,
                ConfidenceLevel::Low,
                [...$signals, 'no-seeding-record'],
            );
        }

        // Relevant content without a reliable link to a QDS seeding.
        $strongRelevance = $strongRecognitions !== [] || $targetedHashtags !== [];

        if ($strongRelevance) {
            return new ClassificationResult(
                MentionType::LikelyOrganic,
                ConfidenceLevel::Medium,
                [...$signals, 'no-disclosure-label', 'no-seeding-record'],
            );
        }

        // Only weak recognition signals: insufficient evidence → review.
        return new ClassificationResult(
            MentionType::Unknown,
            ConfidenceLevel::Low,
            [...$signals, 'no-disclosure-label', 'no-seeding-record', 'weak-signal'],
        );
    }

    /** @return list<string> */
    private function evidenceSignals(EvidenceBundle $evidence): array
    {
        $signals = [];

        foreach ($evidence->recognitions as $recognition) {
            $signals[] = sprintf(
                'recognition:%s:%s:%s',
                $recognition['type'],
                $recognition['brand'],
                $recognition['level']->value,
            );
        }

        foreach ($evidence->hashtagMatches as $match) {
            $signals[] = sprintf('hashtag:%s:%s', $match['original'], strtolower($match['scope']));
        }

        foreach ($evidence->ambiguousHashtags as $hashtag) {
            $signals[] = sprintf('ambiguous-hashtag:%s', $hashtag);
        }

        return $signals;
    }

    /**
     * Shipments whose brand/product/campaign aligns with at least one piece
     * of independent relevance evidence. A shipment with no aligned
     * relevance is NOT a link — it never proves anything alone.
     *
     * @param  list<array{type: string, brand: string, level: ConfidenceLevel}>  $strongRecognitions
     * @param  list<array{type: string, brand: string, level: ConfidenceLevel}>  $weakRecognitions
     * @param  list<array{original: string, scope: string, campaign_id: int|null, brand_id: int|null, brand_name: string|null, product_label: string|null}>  $targetedHashtags
     * @return list<ShipmentEvidence>
     */
    private function alignedShipments(
        EvidenceBundle $evidence,
        array $strongRecognitions,
        array $weakRecognitions,
        array $targetedHashtags,
    ): array {
        $aligned = [];

        foreach ($evidence->shipments as $shipment) {
            if (! $this->shipmentAligns($shipment, [...$strongRecognitions, ...$weakRecognitions], $targetedHashtags)) {
                continue;
            }

            // A shipment KNOWN to be outside the publication window is no
            // link at all: content published before the product went out
            // (or long after the window) cannot evidence this seeding.
            if ($this->timingKnownOutsideWindow($shipment, $evidence)) {
                continue;
            }

            $aligned[] = $shipment;
        }

        return $aligned;
    }

    private function timingKnownOutsideWindow(ShipmentEvidence $shipment, EvidenceBundle $evidence): bool
    {
        $anchor = $shipment->anchorDate();

        if ($anchor === null || $evidence->publishedAt === null) {
            return false;
        }

        $windowDays = $this->shipmentWindowDays();

        return $evidence->publishedAt->lessThan($anchor)
            || $evidence->publishedAt->greaterThan($anchor->addDays($windowDays));
    }

    /**
     * @param  list<array{type: string, brand: string, level: ConfidenceLevel}>  $recognitions
     * @param  list<array{original: string, scope: string, campaign_id: int|null, brand_id: int|null, brand_name: string|null, product_label: string|null}>  $targetedHashtags
     */
    private function shipmentAligns(ShipmentEvidence $shipment, array $recognitions, array $targetedHashtags): bool
    {
        foreach ($recognitions as $recognition) {
            if ($shipment->brandName !== null
                && mb_strtolower($recognition['brand']) === mb_strtolower($shipment->brandName)) {
                return true;
            }
        }

        foreach ($targetedHashtags as $match) {
            if ($shipment->brandId !== null && $match['brand_id'] === $shipment->brandId) {
                return true;
            }

            if ($shipment->campaignId !== null && $match['campaign_id'] === $shipment->campaignId) {
                return true;
            }

            if ($shipment->productLabel !== null
                && $match['product_label'] !== null
                && mb_strtolower($match['product_label']) === mb_strtolower($shipment->productLabel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<ShipmentEvidence>  $aligned
     * @param  list<array{type: string, brand: string, level: ConfidenceLevel}>  $strongRecognitions
     * @param  list<array{original: string, scope: string, campaign_id: int|null, brand_id: int|null, brand_name: string|null, product_label: string|null}>  $targetedHashtags
     */
    private function shipmentHasStrongRelevance(array $aligned, array $strongRecognitions, array $targetedHashtags): bool
    {
        foreach ($aligned as $shipment) {
            if ($this->shipmentAligns($shipment, $strongRecognitions, [])) {
                return true;
            }

            if ($this->shipmentAligns($shipment, [], $targetedHashtags) && $strongRecognitions !== []) {
                // Hashtag link corroborated by a strong recognition of the
                // same content — combined evidence is strong.
                return true;
            }
        }

        return false;
    }

    /** @param list<ShipmentEvidence> $aligned */
    private function timingSatisfied(array $aligned, EvidenceBundle $evidence): bool
    {
        if ($evidence->publishedAt === null) {
            return false;
        }

        $windowDays = $this->shipmentWindowDays();

        foreach ($aligned as $shipment) {
            $anchor = $shipment->anchorDate();

            if ($anchor === null) {
                continue;
            }

            if ($evidence->publishedAt->greaterThanOrEqualTo($anchor)
                && $evidence->publishedAt->lessThanOrEqualTo($anchor->addDays($windowDays))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Per-tenant gift-link window (ADR-0025): enrichment always runs under
     * TenantContext::runAs, so the active tenant's Settings → Monitoring
     * value applies; tenant-less callers get the config default.
     */
    private function shipmentWindowDays(): int
    {
        $this->settings ??= app(MonitoringSettingsResolver::class);

        return $this->settings->shipmentWindowDays();
    }
}
