<?php

namespace App\Modules\Monitoring\Livewire\Settings;

use App\Models\User;
use App\Modules\Monitoring\Models\MonitoringSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Settings → Monitoring (ADR-0024/0025): one page for the four per-tenant
 * monitoring values — gift-link window, engagement-trend window, story
 * media keep-time, and message-history keep-time — in the same plain-
 * language single-setting-editor style as EMV/Reach. Saving appends a NEW
 * monitoring_settings row (history is never edited in place).
 *
 * Page needs settings.view; saving re-authorizes on
 * monitoring-settings.manage (ADMIN) via MonitoringSettingPolicy.
 */
class MonitoringSettings extends Component
{
    /** Bounds mirrored by the DB CHECK constraint. */
    private const SHIPMENT_RANGE = [1, 365];

    private const TREND_RANGE = [7, 90];

    private const RETENTION_RANGE = [1, 3650];

    public string $shipmentDays = '';

    public string $trendDays = '';

    public bool $storyCleanupEnabled = false;

    public string $storyDays = '';

    public bool $commsCleanupEnabled = false;

    public string $commsDays = '';

    public ?string $formError = null;

    public function mount(): void
    {
        $this->authorize('viewAny', MonitoringSetting::class);

        // Latest row for the requester's tenant (TenantScope; HTTP always
        // has a tenant context), else the config defaults.
        $row = MonitoringSetting::query()->latest('id')->first();

        $shipment = $row->shipment_window_days
            ?? (int) config('qds.enrichment.attribution.shipment_window_days');
        $trend = $row->engagement_trend_window_days
            ?? (int) config('qds.enrichment.engagement_trend_window_days');
        $story = $row->story_retention_days
            ?? (int) config('qds.ingestion.media_retention_days');
        $comms = $row->communication_retention_days
            ?? (int) config('qds.gdpr.communication_log_retention_days');

        $this->shipmentDays = (string) $shipment;
        $this->trendDays = (string) $trend;
        $this->storyCleanupEnabled = $story > 0;
        $this->storyDays = (string) ($story > 0 ? $story : 180);
        $this->commsCleanupEnabled = $comms > 0;
        $this->commsDays = (string) ($comms > 0 ? $comms : 365);
    }

    public function save(): void
    {
        $this->authorize('create', MonitoringSetting::class);

        $this->formError = $this->friendlyError();

        if ($this->formError !== null) {
            return;
        }

        MonitoringSetting::query()->create([
            'shipment_window_days' => $this->int($this->shipmentDays),
            'engagement_trend_window_days' => $this->int($this->trendDays),
            'story_retention_days' => $this->storyCleanupEnabled ? $this->int($this->storyDays) : 0,
            'communication_retention_days' => $this->commsCleanupEnabled ? $this->int($this->commsDays) : 0,
            'updated_by' => Auth::id(),
        ]);

        $this->dispatch('notify', type: 'success', message: 'Monitoring settings saved.');
    }

    private function friendlyError(): ?string
    {
        [$min, $max] = self::SHIPMENT_RANGE;
        if (! $this->isIntBetween($this->shipmentDays, $min, $max)) {
            return "\"Gift link window\" must be a whole number of days between {$min} and {$max}.";
        }

        [$min, $max] = self::TREND_RANGE;
        if (! $this->isIntBetween($this->trendDays, $min, $max)) {
            return "\"Engagement trend window\" must be a whole number of days between {$min} and {$max}.";
        }

        [$min, $max] = self::RETENTION_RANGE;
        if ($this->storyCleanupEnabled && ! $this->isIntBetween($this->storyDays, $min, $max)) {
            return "\"Keep story files for\" must be a whole number of days between {$min} and {$max} — or switch the cleanup off to keep them forever.";
        }

        if ($this->commsCleanupEnabled && ! $this->isIntBetween($this->commsDays, $min, $max)) {
            return "\"Keep message history for\" must be a whole number of days between {$min} and {$max} — or switch the cleanup off to keep it forever.";
        }

        return null;
    }

    private function isIntBetween(string $value, int $min, int $max): bool
    {
        $trimmed = trim($value);

        if (preg_match('/^\d+$/', $trimmed) !== 1) {
            return false;
        }

        $number = (int) $trimmed;

        return $number >= $min && $number <= $max;
    }

    private function int(string $value): int
    {
        return (int) trim($value);
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }

    public function render(): View
    {
        return view('livewire.monitoring.monitoring-settings', [
            'canManage' => $this->user()->can('create', MonitoringSetting::class),
        ]);
    }
}
