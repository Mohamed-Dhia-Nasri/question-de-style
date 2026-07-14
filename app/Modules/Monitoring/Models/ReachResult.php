<?php

namespace App\Modules\Monitoring\Models;

use App\Shared\Casts\AsValueObject;
use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\ValueObjects\ReachEstimate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One calculated estimated-reach figure for one ContentItem (REQ-M1-006,
 * ADR-0022). Append-only: snapshots the reach configuration, formula
 * version, inputs, and the ReachEstimate envelope so past estimated reach
 * stays reproducible after any configuration change. Tenant-owned (ADR-0019).
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $content_item_id
 * @property int $reach_configuration_id
 * @property string $formula_version
 * @property ReachEstimate $value
 * @property list<array<string, mixed>> $inputs
 * @property CarbonImmutable $calculated_at
 */
class ReachResult extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'content_item_id',
        'reach_configuration_id',
        'formula_version',
        'value',
        'inputs',
        'calculated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => AsValueObject::class.':'.ReachEstimate::class,
            'inputs' => 'array',
            'calculated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('reach_results is append-only: past estimated reach stays reproducible (ADR-0022).');
        });

        static::deleting(function (): never {
            throw new LogicException('reach_results is append-only: past estimated reach stays reproducible (ADR-0022).');
        });
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return BelongsTo<ReachConfiguration, $this> */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(ReachConfiguration::class, 'reach_configuration_id');
    }
}
