<?php

namespace App\Platform\Enrichment\Matching;

use App\Modules\Monitoring\Contracts\ContentMatchFeedback;
use App\Modules\Monitoring\Models\Mention;
use App\Platform\Enrichment\Attribution\ShipmentEvidence;
use App\Platform\Enrichment\Contracts\ShipmentContentLinker;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\Tenancy\TenantContext;

/**
 * REQ-M3-008 / AC-M3-013 — materializes the shipment↔content links from the
 * SEEDED mentions attribution already produces (the Mention IS the match
 * record: its ConfidenceAssessment carries the match confidence and its
 * signals carry the shipment proving records, AC-M1-003/AC-M1-020).
 *
 * Auto-links only above the codebase's established review cut-point
 * (AI_ASSESSED at HIGH/MEDIUM — the complement of
 * ConfidenceAssessment::needsHumanReview()) or after a human blessed the
 * mention through the shared review queue (DP-004). Low-confidence outcomes
 * are ALREADY queued for review by the P1 pipeline and are never auto-linked.
 *
 * Writes nothing directly: M3 rows go through the ShipmentContentLinker
 * contract; the M1-owned mentions.campaign_id goes through XMC-002
 * (ContentMatchFeedback), and only when the linked shipments resolve to
 * exactly one parent campaign (ambiguity is left to humans, spec D3).
 */
class SeededContentLinker
{
    public function __construct(
        private readonly ShipmentContentLinker $links,
        private readonly ContentMatchFeedback $feedback,
    ) {}

    /**
     * @param  \DateTimeInterface|null  $since  only re-walk mentions updated
     *                                          at/after this instant (deep-review GAP-2: an unbounded pass
     *                                          re-processes ALL historical SEEDED mentions every run and
     *                                          grows without limit); null = full rescan. Reclassifications
     *                                          and review decisions bump updated_at, so they re-enter the
     *                                          window; the link path stays idempotent either way.
     */
    public function run(?\DateTimeInterface $since = null): LinkSummary
    {
        $linked = 0;
        $alreadyLinked = 0;
        $staleReferences = 0;
        $withoutReferences = 0;
        $campaignsConfirmed = 0;

        $mentions = Mention::query()
            ->whereNotNull('content_item_id')
            ->where('mention_type', MentionType::Seeded->value)
            ->when($since !== null, fn ($query) => $query->where('updated_at', '>=', $since))
            ->with('contentItem.platformAccount')
            ->lazyById(200);

        foreach ($mentions as $mention) {
            if (! $this->linkable($mention)) {
                continue;
            }

            $shipmentIds = $this->shipmentReferences($mention);

            if ($shipmentIds === []) {
                // A human blessed SEEDED without a shipment reference — never
                // guess; the operator links manually on the shipments panel.
                $withoutReferences++;

                continue;
            }

            $content = $mention->contentItem;

            // ADR-0019: this pass sweeps mentions across ALL tenants — each
            // mention is a unit of work under ITS content's tenant (runAs
            // restores after), so shipment resolution is tenant-scoped
            // (a cross-tenant shipment reference resolves to null → stale)
            // and pivot/feedback/audit writes stamp the right owner.
            app(TenantContext::class)->runAs($content->tenant_id, function () use (
                $content,
                $shipmentIds,
                &$linked,
                &$alreadyLinked,
                &$staleReferences,
                &$campaignsConfirmed
            ): void {
                $campaignIds = [];
                $linkedShipmentIds = [];

                foreach ($shipmentIds as $shipmentId) {
                    $outcome = $this->links->link($shipmentId, $content);

                    if ($outcome === null) {
                        $staleReferences++;

                        continue;
                    }

                    $outcome->newlyLinked ? $linked++ : $alreadyLinked++;
                    $linkedShipmentIds[] = $shipmentId;

                    if ($outcome->campaignId !== null) {
                        $campaignIds[$outcome->campaignId] = true;
                    }
                }

                // XMC-002: attribute the mention to the parent campaign only when
                // the evidence is unambiguous (exactly one distinct campaign).
                // Only the shipments that actually LINKED scope the write (a
                // stale/foreign reference must not vouch for a sibling); the
                // evidenced mention(s) only — siblings are untouched (C1).
                if (count($campaignIds) === 1) {
                    $this->feedback->confirm($content, (int) array_key_first($campaignIds), $linkedShipmentIds);
                    $campaignsConfirmed++;
                }
            });
        }

        return new LinkSummary($linked, $alreadyLinked, $staleReferences, $withoutReferences, $campaignsConfirmed);
    }

    /**
     * Auto path: AI_ASSESSED above the review cut-point. Human path: any
     * human-blessed state. needsHumanReview() rows stay queued (AC-M3-013).
     */
    private function linkable(Mention $mention): bool
    {
        $assessment = $mention->classification;

        if ($assessment->verificationStatus === VerificationStatus::AiAssessed) {
            // Brand-only (product-unconfirmed) SEEDED stays for human review;
            // never auto-link a shipment on brand match alone.
            if (in_array('product-unconfirmed', $assessment->signals, true)) {
                return false;
            }

            return in_array($assessment->confidenceLevel, [ConfidenceLevel::High, ConfidenceLevel::Medium], true);
        }

        return in_array($assessment->verificationStatus, [
            VerificationStatus::HumanReviewed,
            VerificationStatus::HumanCorrected,
            VerificationStatus::Confirmed,
        ], true);
    }

    /** @return list<int> */
    private function shipmentReferences(Mention $mention): array
    {
        $ids = [];

        foreach ($mention->classification->signals as $signal) {
            $id = ShipmentEvidence::shipmentIdFrom($signal);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
