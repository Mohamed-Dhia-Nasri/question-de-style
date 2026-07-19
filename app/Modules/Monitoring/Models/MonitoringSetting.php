<?php

namespace App\Modules\Monitoring\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One per-tenant monitoring settings snapshot (ADR-0025). Append-only:
 * saves insert a NEW row; the latest row per tenant wins. Read ONLY
 * through MonitoringSettingsResolver (config fallback + context safety) —
 * never via a latest()-style accessor from platform code, which would
 * repeat the ADR-0019 cross-tenant-bleed limitation of
 * MonitoringPlanSetting::current().
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $shipment_window_days
 * @property int $engagement_trend_window_days
 * @property int $story_retention_days
 * @property int|null $keyframe_retention_days
 * @property int $communication_retention_days
 * @property int|null $updated_by
 */
class MonitoringSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'shipment_window_days',
        'engagement_trend_window_days',
        'story_retention_days',
        'keyframe_retention_days',
        'communication_retention_days',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'shipment_window_days' => 'integer',
            'engagement_trend_window_days' => 'integer',
            'story_retention_days' => 'integer',
            'keyframe_retention_days' => 'integer',
            'communication_retention_days' => 'integer',
        ];
    }
}
