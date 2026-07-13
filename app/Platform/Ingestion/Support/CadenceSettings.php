<?php

namespace App\Platform\Ingestion\Support;

use App\Platform\Ingestion\Models\MonitoringPlanSetting;

/**
 * The effective monitoring plan: the operator-chosen row from
 * monitoring_plan_settings when one exists (set in-app on
 * /monitoring/plan), else the config defaults. Single read path for every
 * cadence decision so the plan can never be half-applied.
 *
 * The row is resolved once per instance; the container binds this class
 * per-request/per-job scope, so long-running workers see fresh settings
 * on each job.
 */
class CadenceSettings
{
    public const APIFY_PLANS = ['FREE', 'STARTER', 'SCALE', 'BUSINESS'];

    private ?MonitoringPlanSetting $row;

    private bool $resolved = false;

    public function __construct(
        /** Explicit row = preview mode (the plan page estimates unsaved selections). */
        private readonly ?MonitoringPlanSetting $override = null,
    ) {}

    private function row(): ?MonitoringPlanSetting
    {
        if ($this->override !== null) {
            return $this->override;
        }

        if (! $this->resolved) {
            $this->row = MonitoringPlanSetting::current();
            $this->resolved = true;
        }

        return $this->row;
    }

    /** Content-poll spacing for creators NOT on a running campaign. */
    public function baselineContentIntervalHours(): int
    {
        $row = $this->row();

        return $row !== null
            ? $row->baseline_content_interval_hours
            : (int) config('qds.ingestion.baseline_content_interval_hours');
    }

    /** Content-poll spacing for campaign/seeding-attached creators. */
    public function campaignContentIntervalHours(): int
    {
        $row = $this->row();

        return $row !== null
            ? $row->campaign_content_interval_hours
            : (int) config('qds.ingestion.campaign_content_interval_hours');
    }

    /** Story polls per day (0 = off). */
    public function storiesPerDay(): int
    {
        $row = $this->row();

        return $row !== null
            ? $row->stories_per_day
            : (int) config('qds.ingestion.stories_per_day');
    }

    public function profilePollIntervalHours(): int
    {
        $row = $this->row();

        return $row !== null
            ? $row->profile_poll_interval_hours
            : (int) config('qds.ingestion.profile_poll_interval_hours');
    }

    /** Apify plan tier — cost-estimation display only, never gates code. */
    public function apifyPlan(): string
    {
        $row = $this->row();

        return $row !== null ? $row->apify_plan : 'STARTER';
    }
}
