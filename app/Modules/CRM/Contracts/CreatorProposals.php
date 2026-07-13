<?php

namespace App\Modules\CRM\Contracts;

use App\Modules\CRM\DTO\CreatorProposal;
use App\Modules\CRM\Models\Creator;

/**
 * XMC-001 — creator proposal, M1/M2 → M3 (module-3 §5, ownership matrix
 * rule 3): when Monitoring or Discovery observes a creator not yet in the
 * system, it PROPOSES the record here; SVC-CRM is the only writer of
 * ENT-Creator / ENT-PlatformAccount (AC-M3-002). Proposing modules never
 * write those tables directly.
 *
 * Implemented in M3 Step 2 by CreatorProposalIntake. Per ADR-0014
 * (operator-managed identity) there is NO dedup, matching, or review queue:
 * every proposal creates a fresh Creator; duplicate identities are
 * reconciled by an operator by hand. XMC-002 (content-match feedback,
 * M3 → M1) is Step-3 scope — declared in module-3 §5, no code yet.
 */
interface CreatorProposals
{
    /**
     * Accept a proposed creator + platform account observed by a non-owner
     * module and return the freshly created Creator (AC-M3-002; no dedup
     * per ADR-0014).
     */
    public function propose(CreatorProposal $proposal): Creator;
}
