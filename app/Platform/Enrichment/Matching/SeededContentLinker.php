<?php

namespace App\Platform\Enrichment\Matching;

use App\Modules\Monitoring\Contracts\ContentMatchFeedback;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Enrichment\Attribution\ShipmentEvidence;
use App\Platform\Enrichment\Contracts\ShipmentContentLinker;
use App\Shared\Enums\ConfidenceLevel;
use App\Shared\Enums\MentionType;
use App\Shared\Enums\VerificationStatus;
use App\Shared\Tenancy\TenantContext;
use Throwable;

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
        // The scheduled sweep: every SEEDED mention in the window, across all
        // tenants. Windowing is GAP-2's growth bound; run(null) (--all) is the
        // full historical rescan.
        return $this->linkMentions(
            Mention::query()
                ->whereNotNull('content_item_id')
                ->where('mention_type', MentionType::Seeded->value)
                ->when($since !== null, fn ($query) => $query->where('updated_at', '>=', $since))
                ->with('contentItem.platformAccount')
                ->lazyById(200)
        );
    }

    /**
     * Instant path (spec: detection feels immediate): link a SINGLE content
     * item's SEEDED mention(s) the moment enrichment classifies it, instead of
     * waiting for the next scheduled qds:link-seeded-content sweep. Same
     * per-mention logic and idempotency as run() — only the candidate set
     * differs (this one post, not a time window). Pure and ungated, like
     * run(); callers use linkFreshlySeeded() for the gated fire-and-forget
     * wrapper.
     */
    public function linkForContent(ContentItem $content): LinkSummary
    {
        return $this->linkMentions(
            Mention::query()
                ->where('content_item_id', $content->id)
                ->where('mention_type', MentionType::Seeded->value)
                ->with('contentItem.platformAccount')
                ->get()
        );
    }

    /**
     * Instant-link entrypoint for the live enrichment paths (the pipeline and
     * the async VLM / speech re-classify jobs). The moment attribution writes
     * a SEEDED mention for a post, link it now so the detection surfaces in
     * the CRM in seconds instead of after a full qds:link-seeded-content
     * cadence.
     *
     * Self-gating and fire-and-forget by contract: no-ops unless
     * qds.matching.enabled is on AND at least one SEEDED mention was just
     * written, and a linking failure is reported but NEVER propagated — the
     * enrichment run must not fail over a link, and the scheduled sweep is the
     * idempotent backstop that heals anything missed. Stories carry no
     * shipment↔content link (run()/linkForContent scope to content_item_id),
     * so a Story target no-ops.
     *
     * @param  list<Mention>  $mentions  what AttributionService::enrich just returned
     */
    public function linkFreshlySeeded(ContentItem|Story $target, array $mentions): void
    {
        if (! $target instanceof ContentItem || ! config('qds.matching.enabled')) {
            return;
        }

        $seeded = collect($mentions)->contains(
            fn (Mention $mention): bool => $mention->mention_type === MentionType::Seeded
        );

        if (! $seeded) {
            return;
        }

        try {
            $this->linkForContent($target);
        } catch (Throwable $e) {
            // Never fail the enrichment run over a link; the scheduled sweep
            // (qds:link-seeded-content) is the idempotent backstop.
            report($e);
        }
    }

    /**
     * Link a batch of SEEDED mentions, accumulating each mention's outcome
     * into one LinkSummary. Shared by the scheduled sweep (run()) and the
     * instant path (linkForContent()).
     *
     * @param  iterable<Mention>  $mentions
     */
    private function linkMentions(iterable $mentions): LinkSummary
    {
        $counters = [
            'linked' => 0,
            'alreadyLinked' => 0,
            'staleReferences' => 0,
            'withoutReferences' => 0,
            'campaignsConfirmed' => 0,
        ];

        foreach ($mentions as $mention) {
            $this->linkMention($mention, $counters);
        }

        return new LinkSummary(
            $counters['linked'],
            $counters['alreadyLinked'],
            $counters['staleReferences'],
            $counters['withoutReferences'],
            $counters['campaignsConfirmed'],
        );
    }

    /**
     * One SEEDED mention → its shipment links, confirming the parent campaign
     * when unambiguous. Mutates $counters in place (see linkMentions()).
     *
     * @param  array{linked:int,alreadyLinked:int,staleReferences:int,withoutReferences:int,campaignsConfirmed:int}  $counters
     */
    private function linkMention(Mention $mention, array &$counters): void
    {
        if (! $this->linkable($mention)) {
            return;
        }

        $shipmentIds = $this->shipmentReferences($mention);

        if ($shipmentIds === []) {
            // A human blessed SEEDED without a shipment reference — never
            // guess; the operator links manually on the shipments panel.
            $counters['withoutReferences']++;

            return;
        }

        $content = $mention->contentItem;

        // ADR-0019: each mention is a unit of work under ITS content's tenant
        // (runAs restores after), so shipment resolution is tenant-scoped (a
        // cross-tenant shipment reference resolves to null → stale) and
        // pivot/feedback/audit writes stamp the right owner. The sweep spans
        // all tenants; the instant callers already run inside the content's
        // tenant, and re-entering the same context is a safe set-and-restore.
        app(TenantContext::class)->runAs($content->tenant_id, function () use ($content, $shipmentIds, &$counters): void {
            $campaignIds = [];
            $linkedShipmentIds = [];

            foreach ($shipmentIds as $shipmentId) {
                $outcome = $this->links->link($shipmentId, $content);

                if ($outcome === null) {
                    $counters['staleReferences']++;

                    continue;
                }

                $outcome->newlyLinked ? $counters['linked']++ : $counters['alreadyLinked']++;
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
                $counters['campaignsConfirmed']++;
            }
        });
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
