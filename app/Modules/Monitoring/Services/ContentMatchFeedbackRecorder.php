<?php

namespace App\Modules\Monitoring\Services;

use App\Modules\Monitoring\Contracts\ContentMatchFeedback;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Platform\Enrichment\Attribution\ShipmentEvidence;
use App\Shared\Audit\AuditLogger;

/**
 * M1-side implementation of XMC-002: the single write path for
 * mentions.campaign_id (ENT-Mention is M1-owned). Confirm fills the
 * attribution where none exists AND the mention's own evidence supports it
 * (deep-review finding C1 — a blanket stamp over all unattributed mentions
 * leaked one brand's campaign onto sibling mentions of a multi-brand post);
 * deny retracts exactly the named campaign. A campaign_id a human (or
 * earlier run) already set to a DIFFERENT campaign is never overwritten —
 * conflicting attributions are resolved through the review workflow, not
 * clobbered (DP-004). Every decision is audit-logged; the classification
 * envelope itself is never touched here.
 */
class ContentMatchFeedbackRecorder implements ContentMatchFeedback
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function confirm(ContentItem $content, int $campaignId, array $shipmentIds): void
    {
        $candidates = $content->mentions()->whereNull('campaign_id')->get();

        // Evidence scope (C1): a mention qualifies when its classification
        // signals reference one of the shipments that justified this match
        // (AC-M1-003 proving records). The no-evidence fallback applies
        // ONLY when the content carries a single mention IN TOTAL (the
        // pure manual-link case, where the classifier never saw the
        // shipment and no other subject competes). A lone unattributed
        // SIBLING is never fallback-stamped: once the evidenced mention is
        // attributed, a repeat confirm (the idempotent linker re-visits
        // links every pass) would otherwise stamp the remaining wrong-brand
        // sibling — the original C1 corruption, delayed one run
        // (verification finding on the first C1 fix). Never guess.
        $evidenced = $candidates->filter(
            fn (Mention $mention): bool => $this->referencesAny($mention, $shipmentIds)
        );

        if ($evidenced->isEmpty()
            && $candidates->count() === 1
            && $content->mentions()->count() === 1) {
            $evidenced = $candidates;
        }

        foreach ($evidenced as $mention) {
            /** @var Mention $mention */
            $mention->forceFill(['campaign_id' => $campaignId])->save();

            $this->audit->record('content_match.confirmed', $mention, [
                'content_item_id' => $content->id,
                'campaign_id' => $campaignId,
                'shipment_ids' => $shipmentIds,
            ]);
        }
    }

    /** @param list<int> $shipmentIds */
    private function referencesAny(Mention $mention, array $shipmentIds): bool
    {
        foreach ($mention->classification->signals as $signal) {
            $id = ShipmentEvidence::shipmentIdFrom($signal);

            if ($id !== null && in_array($id, $shipmentIds, true)) {
                return true;
            }
        }

        return false;
    }

    public function deny(ContentItem $content, int $campaignId): void
    {
        $mentions = $content->mentions()->where('campaign_id', $campaignId)->get();

        foreach ($mentions as $mention) {
            /** @var Mention $mention */
            $mention->forceFill(['campaign_id' => null])->save();

            $this->audit->record('content_match.denied', $mention, [
                'content_item_id' => $content->id,
                'campaign_id' => $campaignId,
            ]);
        }
    }
}
