<?php

namespace App\Modules\Monitoring\Contracts;

use App\Modules\CRM\Models\Creator;
use App\Modules\Monitoring\Models\MonitoredSubject;

/**
 * Roster enrollment seam, M3 → M1: ENT-MonitoredSubject is Module 1's own
 * configuration record (ownership matrix), so when the CRM's creator
 * lifecycle needs a roster change it asks HERE and the M1-side service
 * performs the only write — the mirror image of XMC-002, where M3 asks M1
 * to stamp mentions.campaign_id.
 *
 * Purpose (product decision 2026-07-07): every creator is monitored from
 * the moment it exists — creation enrolls it on the active roster so the
 * next scheduled cycle (AC-M1-001) picks it up without operator action.
 * Flagged for a module-3 §5 canon amendment: the declared XMC list does
 * not yet name this seam.
 */
interface RosterEnrollment
{
    /**
     * Put the creator on the active CREATOR roster (ADR-0011). Idempotent —
     * an existing roster entry is returned untouched, so an operator's
     * later platform filter or deactivation is never overwritten.
     */
    public function enroll(Creator $creator): MonitoredSubject;

    /**
     * Remove the creator's roster entries — the creator is being deleted
     * and roster configuration is lifecycle-coupled to it. Entries with
     * mention history make the DB's restrict FK abort the caller's
     * transaction: monitoring HISTORY always blocks a creator delete,
     * roster CONFIGURATION alone never does.
     */
    public function withdraw(Creator $creator): void;
}
