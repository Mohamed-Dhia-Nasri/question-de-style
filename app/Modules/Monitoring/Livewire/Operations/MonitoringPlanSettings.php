<?php

namespace App\Modules\Monitoring\Livewire\Operations;

use App\Platform\Ingestion\Models\MonitoringPlanSetting;
use App\Platform\Ingestion\Support\CadenceSettings;
use App\Platform\Ingestion\Support\IngestionCostEstimator;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Operator-facing monitoring plan (/monitoring/plan): Question de Style
 * chooses its own cost/freshness trade-off — polling frequency per tier
 * (campaign vs baseline creators), story polls per day, profile cadence,
 * and the Apify plan used for the live cost estimate. Presets bundle the
 * common choices; every field stays individually adjustable. Settings are
 * DB-backed (monitoring_plan_settings) and take effect on the next cycle
 * — no deploy needed. Route-gated on monitoring.manage.
 */
class MonitoringPlanSettings extends Component
{
    public const CONTENT_INTERVALS = [
        6 => 'Every 6 hours (fastest)',
        12 => 'Twice a day',
        24 => 'Once a day',
        84 => 'Twice a week',
        168 => 'Once a week',
    ];

    public const STORY_OPTIONS = [
        0 => 'Off — don\'t collect stories',
        1 => 'Once a day',
        2 => 'Twice a day',
        3 => '3 times a day',
        6 => 'Every 4 hours',
    ];

    public const PROFILE_INTERVALS = [
        24 => 'Once a day',
        168 => 'Once a week (recommended)',
        720 => 'Once a month',
    ];

    public int $baseline = 84;

    public int $campaign = 12;

    public int $stories = 1;

    public int $profile = 168;

    public string $apifyPlan = 'STARTER';

    public bool $saved = false;

    public function mount(): void
    {
        $settings = app(CadenceSettings::class);

        $this->baseline = $settings->baselineContentIntervalHours();
        $this->campaign = $settings->campaignContentIntervalHours();
        $this->stories = $settings->storiesPerDay();
        $this->profile = $settings->profilePollIntervalHours();
        $this->apifyPlan = $settings->apifyPlan();
    }

    /** Any change to the selection invalidates the "Saved" confirmation. */
    public function updated(): void
    {
        $this->saved = false;
    }

    public function save(): void
    {
        $this->validate([
            'baseline' => 'required|integer|in:'.implode(',', array_keys(self::CONTENT_INTERVALS)),
            'campaign' => 'required|integer|in:'.implode(',', array_keys(self::CONTENT_INTERVALS)),
            'stories' => 'required|integer|min:0|max:6',
            'profile' => 'required|integer|in:'.implode(',', array_keys(self::PROFILE_INTERVALS)),
            'apifyPlan' => 'required|in:'.implode(',', CadenceSettings::APIFY_PLANS),
        ]);

        MonitoringPlanSetting::query()->create([
            'baseline_content_interval_hours' => $this->baseline,
            'campaign_content_interval_hours' => $this->campaign,
            'stories_per_day' => $this->stories,
            'profile_poll_interval_hours' => $this->profile,
            'apify_plan' => $this->apifyPlan,
            'updated_by' => auth()->id(),
        ]);

        $this->saved = true;
    }

    public function render(IngestionCostEstimator $estimator): View
    {
        // Estimate the UNSAVED selection live, so the operator sees the
        // price of a choice before committing it.
        $preview = new MonitoringPlanSetting([
            'baseline_content_interval_hours' => $this->baseline,
            'campaign_content_interval_hours' => $this->campaign,
            'stories_per_day' => $this->stories,
            'profile_poll_interval_hours' => $this->profile,
            'apify_plan' => $this->apifyPlan,
        ]);

        $settings = new CadenceSettings($preview);

        $roster = $estimator->rosterFromDatabase();
        $estimate = $estimator->estimate($settings, $roster);

        return view('livewire.monitoring.monitoring-plan-settings', [
            'roster' => $roster,
            'estimate' => $estimate,
            'services' => $estimator->perService($settings, $roster, $estimate),
            'warnings' => $this->warnings(),
            'contentIntervals' => self::CONTENT_INTERVALS,
            'storyOptions' => self::STORY_OPTIONS,
            'profileIntervals' => self::PROFILE_INTERVALS,
            'apifyPlans' => CadenceSettings::APIFY_PLANS,
            'planFees' => IngestionCostEstimator::PLAN_FEES,
        ]);
    }

    /**
     * Plain-language consequences of risky choices, shown at the moment of
     * decision — not buried in documentation.
     *
     * @return list<string>
     */
    private function warnings(): array
    {
        $warnings = [];

        if ($this->stories === 1) {
            $warnings[] = 'Stories disappear after 24 hours. Checking once a day means a single failed check can miss a whole day of stories — twice a day is the safe minimum if stories matter to you.';
        }

        if ($this->stories === 0) {
            $warnings[] = 'Story collection is off — stories will not appear in monitoring or campaign results.';
        }

        if ($this->baseline >= 168) {
            $warnings[] = 'Creators outside campaigns are checked once a week — their new posts can take up to a week to appear.';
        }

        if ($this->campaign >= 24) {
            $warnings[] = 'Campaign creators are checked once a day or less — you will not see how fast a seeded post takes off during its first hours.';
        }

        return $warnings;
    }
}
