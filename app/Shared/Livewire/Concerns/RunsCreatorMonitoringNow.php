<?php

namespace App\Shared\Livewire\Concerns;

use App\Modules\Monitoring\Contracts\RosterEnrollment;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Platform\Ingestion\Contracts\IngestionService;

/**
 * The operator's "run monitoring now" lever, shared by every page that
 * shows one creator (CRM profile, monitoring creator detail): an on-demand
 * single-creator ingestion cycle for people who don't want to wait for the
 * scheduled roster cycle. Gated on the M1 roster permission
 * (monitoring.manage via MonitoredSubjectPolicy) — it is a monitoring
 * action wherever it is rendered.
 *
 * Host components must expose `public Creator $creator`.
 */
trait RunsCreatorMonitoringNow
{
    /**
     * Poll this creator's accounts immediately instead of waiting for the
     * scheduled cycle. Enrolls the creator on the roster first (idempotent)
     * so pre-enrollment creators are covered by the recurring cycle from
     * here on, then queues the on-demand run.
     */
    public function runMonitoringNow(RosterEnrollment $roster, IngestionService $ingestion): void
    {
        $this->authorize('create', MonitoredSubject::class);

        if (! config('qds.ingestion.enabled')) {
            $this->dispatch('notify', type: 'error', message: 'Ingestion is switched off (QDS_INGESTION_ENABLED) — no run can start.');

            return;
        }

        $accountsCount = $this->creator->platformAccounts()->count();

        if ($accountsCount === 0) {
            $this->dispatch('notify', type: 'error', message: 'This creator has no platform accounts to poll — add one first.');

            return;
        }

        $roster->enroll($this->creator);
        $ingestion->startCreatorCycle($this->creator->id);

        $this->dispatch('notify', type: 'success', message: sprintf(
            'Monitoring run queued for %d account%s — results land as jobs finish. The scheduled cycle keeps covering this creator automatically.',
            $accountsCount,
            $accountsCount === 1 ? '' : 's',
        ));
    }
}
