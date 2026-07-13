<?php

namespace App\Modules\Monitoring\Services;

use App\Modules\CRM\Models\Creator;
use App\Modules\Monitoring\Contracts\RosterEnrollment;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Shared\Enums\MonitoredSubjectType;

/**
 * M1-side body of the roster enrollment seam: the sole writer of
 * monitored_subjects rows created on behalf of the CRM's creator
 * lifecycle. Internal configuration — no Provenance envelope
 * (ENT-MonitoredSubject doctrine).
 */
class RosterEnrollmentService implements RosterEnrollment
{
    public function enroll(Creator $creator): MonitoredSubject
    {
        // An empty platform list means "no filter" to the cycle fan-out
        // (RunMonitoringCycleJob): every account the creator has, present
        // and future, is polled. The label is a display convenience frozen
        // at enrollment time, not an identity field.
        return MonitoredSubject::query()->firstOrCreate(
            [
                'subject_type' => MonitoredSubjectType::Creator,
                'creator_id' => $creator->id,
            ],
            [
                'label' => $creator->display_name,
                'platforms' => [],
                'active' => true,
            ],
        );
    }

    public function withdraw(Creator $creator): void
    {
        MonitoredSubject::query()
            ->where('subject_type', MonitoredSubjectType::Creator->value)
            ->where('creator_id', $creator->id)
            ->delete();
    }
}
