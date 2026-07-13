<?php

namespace App\Modules\Monitoring\Contracts;

use App\Modules\Monitoring\Models\ContentItem;

/**
 * XMC-002 — content-match feedback, M3 → M1 (module-3 §5): Module 3 (or the
 * SeededContentLinker acting on its behalf) confirms or denies a
 * ContentItem↔Campaign match. ENT-Mention is write-owned by M1 (ownership
 * matrix), so mentions.campaign_id — "Set by content-to-campaign matching
 * (REQ-M3-008)" — is only ever written by this contract's M1-side
 * implementation. Corrections feed the human-in-the-loop trail (DP-004).
 * Same owner-side-contract pattern as XMC-001 (CreatorProposals).
 */
interface ContentMatchFeedback
{
    /**
     * Attribute to the campaign ONLY the mentions whose evidence supports it
     * (deep-review finding C1): a mention is stamped when its classification
     * signals reference one of the given shipments (the proving records,
     * AC-M1-003), or when it is the content's ONLY mention (the pure
     * manual-link case; a lone unattributed sibling of an already-attributed
     * mention is never fallback-stamped). Sibling mentions whose evidence points
     * elsewhere are never stamped — a multi-brand post must not leak one
     * brand's campaign onto another brand's mention. Never overwrites a
     * different, already-set campaign.
     *
     * @param  list<int>  $shipmentIds  the shipments evidencing this match
     */
    public function confirm(ContentItem $content, int $campaignId, array $shipmentIds): void;

    /** Retract a campaign attribution from the content's mentions (scoped to exactly that campaign). */
    public function deny(ContentItem $content, int $campaignId): void;
}
