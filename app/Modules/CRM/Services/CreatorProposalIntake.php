<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Contracts\CreatorProposals;
use App\Modules\CRM\DTO\CreatorProposal;
use App\Modules\CRM\Models\Creator;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * XMC-001 body (M3 Step 2): M1/M2 propose an observed creator; SVC-CRM
 * performs the only write (AC-M3-002) via CreatorWriter.
 *
 * Per ADR-0014 (operator-managed identity) the intake attempts NO dedup and
 * no same-person detection: every proposal creates a FRESH Creator with the
 * proposed platform account attached. If an auto-proposed creator duplicates
 * a manually-curated one, an operator deletes the stray from the CRM
 * creators list — identity reconciliation is entirely human.
 *
 * The proposal's mandatory Provenance (DP-002) rides onto the account
 * unchanged. A proposal whose (platform, handle) already exists is refused
 * (PlatformAccountConflict) and the transaction rolls back — no orphan
 * Creator is left behind, and the observing module learns the account is
 * already in the CRM.
 */
class CreatorProposalIntake implements CreatorProposals
{
    public function __construct(private readonly CreatorWriter $writer) {}

    public function propose(CreatorProposal $proposal): Creator
    {
        // ADR-0019: proposals arrive from platform pipelines (M1/M2). The
        // created Creator + PlatformAccount rows take their tenant from the
        // AMBIENT context, which every pipeline entry point establishes from
        // the row it processes (Ingest*Job / Enrich*Job wrap their unit of
        // work in TenantContext::runAs). As of this change the contract has
        // NO production caller yet (tests only) — this guard makes a future
        // caller that forgot to establish context fail loudly HERE, with a
        // pointed message, instead of via a bare NOT NULL violation.
        app(TenantContext::class)->idOrFail();

        return DB::transaction(function () use ($proposal): Creator {
            $creator = $this->writer->createCreator($proposal->displayName);

            $this->writer->addPlatformAccount(
                $creator,
                $proposal->platform,
                $proposal->handle,
                $proposal->provenance,
                $proposal->bio,
                $proposal->externalLinks,
                $proposal->followerCount,
            );

            return $creator;
        });
    }
}
