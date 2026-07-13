<?php

namespace App\Platform\Ingestion\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The operator-chosen monitoring plan (single-row settings): polling
 * frequencies per tier and the Apify plan used for cost estimates.
 * Read through CadenceSettings (which falls back to config defaults when
 * no row exists) — never read directly from polling code.
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $baseline_content_interval_hours
 * @property int $campaign_content_interval_hours
 * @property int $stories_per_day
 * @property int $profile_poll_interval_hours
 * @property string $apify_plan
 * @property int|null $updated_by
 */
class MonitoringPlanSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'baseline_content_interval_hours',
        'campaign_content_interval_hours',
        'stories_per_day',
        'profile_poll_interval_hours',
        'apify_plan',
        'updated_by',
    ];

    /**
     * The plan row for the ACTIVE context: with a tenant context set the
     * TenantScope resolves that tenant's latest row; UI paths always have
     * one.
     *
     * ADR-0019 KNOWN LIMITATION: platform cycle jobs (RunMonitoringCycleJob
     * and the cadence planner) read this with NO tenant context, so they see
     * the latest row of ANY tenant. Per-tenant plan resolution inside the
     * cycle scheduler is deliberately DEFERRED to the tenancy enforcement
     * phase — do not restructure the scheduler around this accessor.
     */
    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }
}
