<?php

namespace App\Modules\Discovery\Contracts;

use App\Modules\CRM\Models\Creator;
use App\Modules\Discovery\Models\GeoAttribution;

/**
 * Operator-assigned creator geography, M3 → M2 (ADR-0018). ENT-GeoAttribution
 * is write-owned by Module 2 (ownership matrix), so the CRM never writes it
 * directly — this owner-side contract is the single write path, the same
 * pattern as XMC-001 (CreatorProposals) and XMC-003 (RosterEnrollment).
 *
 * The operator's entry is the HUMAN half of REQ-M2-003's human-in-the-loop:
 * it carries a ConfidenceAssessment at HUMAN_REVIEWED. Module 2's automatic,
 * signal-based inference (AC-M2-003a) remains deferred with phase P2 and
 * will coexist with — never overwrite — operator assignments (DP-004).
 */
interface CreatorGeography
{
    /** Assign (or update) the creator's geography from manual agency input. */
    public function assign(Creator $creator, ?string $countryCode, ?string $region, ?string $city): GeoAttribution;

    /** Withdraw the operator assignment entirely (renders "unavailable" again). */
    public function clear(Creator $creator): void;
}
