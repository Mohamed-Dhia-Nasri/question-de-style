<?php

namespace App\Shared\Settings;

use App\Modules\Monitoring\Models\MonitoringSetting;
use App\Shared\Tenancy\TenantContext;

/**
 * The ONLY read path for per-tenant monitoring settings (ADR-0025).
 *
 * Two access modes, so no caller can ever see another tenant's row:
 *  - context mode (no argument): resolves the ACTIVE TenantContext's
 *    latest row; with NO context bound it returns the config default —
 *    never `latest()` across tenants (the documented ADR-0019 limitation
 *    of MonitoringPlanSetting::current() that this class must not repeat);
 *  - explicit mode (…For(int $tenantId)): for tenant-less schedulers that
 *    iterate tenants (retention prune commands).
 *
 * Rows are memoized per tenant id for the life of this instance (the
 * class is NOT a singleton — each resolution starts fresh).
 */
class MonitoringSettingsResolver
{
    /** @var array<int, MonitoringSetting|null> */
    private array $rows = [];

    public function __construct(private readonly TenantContext $context) {}

    /** Gift-link (shipment attribution) window; clamped ≥ 1 — no off-state. */
    public function shipmentWindowDays(): int
    {
        $row = $this->contextRow();

        return max(1, $row->shipment_window_days ?? (int) config('qds.enrichment.attribution.shipment_window_days'));
    }

    /** Engagement-trend rolling window N (ADR-0024); clamped to 7–90 domain (matches DB CHECK constraint). */
    public function engagementTrendWindowDays(): int
    {
        $row = $this->contextRow();
        $value = $row->engagement_trend_window_days ?? (int) config('qds.enrichment.engagement_trend_window_days');

        return min(90, max(7, $value));
    }

    /** Story media retention for ONE tenant; 0 = keep forever. */
    public function storyRetentionDaysFor(int $tenantId): int
    {
        $row = $this->rowFor($tenantId);

        return max(0, $row->story_retention_days ?? (int) config('qds.ingestion.media_retention_days'));
    }

    /** Communication-log retention for ONE tenant; 0 = keep forever. */
    public function communicationRetentionDaysFor(int $tenantId): int
    {
        $row = $this->rowFor($tenantId);

        return max(0, $row->communication_retention_days ?? (int) config('qds.gdpr.communication_log_retention_days'));
    }

    private function contextRow(): ?MonitoringSetting
    {
        $tenantId = $this->context->id();

        return $tenantId === null ? null : $this->rowFor($tenantId);
    }

    private function rowFor(int $tenantId): ?MonitoringSetting
    {
        if (! array_key_exists($tenantId, $this->rows)) {
            $this->rows[$tenantId] = MonitoringSetting::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->latest('id')
                ->first();
        }

        return $this->rows[$tenantId];
    }
}
