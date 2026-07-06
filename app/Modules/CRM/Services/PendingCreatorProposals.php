<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Contracts\CreatorProposals;
use App\Modules\CRM\DTO\CreatorProposal;
use App\Modules\CRM\Models\Creator;
use App\Shared\Exceptions\NotYetImplemented;

/**
 * XMC-001 boundary placeholder (same pattern as PendingAnalyticsService /
 * PendingExportService): the contract + DTO shape land in M3 Step 1 so
 * M1/M2 have a target; the proposal body — dedup, merge-aware creation,
 * review queue — is M3 Step-2 work.
 */
class PendingCreatorProposals implements CreatorProposals
{
    public function propose(CreatorProposal $proposal): Creator
    {
        throw NotYetImplemented::service('XMC-001 creator proposal (SVC-CRM)', 'M3 Step 2');
    }
}
