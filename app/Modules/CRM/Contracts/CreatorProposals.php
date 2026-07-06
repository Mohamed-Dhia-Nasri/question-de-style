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
 * Step-1 seam: interface + DTO only, so M1/M2 have a stable target. The
 * implementation (dedup against existing accounts, merge-aware creation,
 * review queue) is M3 Step-2 scope. XMC-002 (content-match feedback,
 * M3 → M1) is Step-3 scope — declared in module-3 §5, no code yet.
 */
interface CreatorProposals
{
    /**
     * Accept a proposed creator + platform account observed by a non-owner
     * module and return the resulting (created or matched) Creator.
     */
    public function propose(CreatorProposal $proposal): Creator;
}
