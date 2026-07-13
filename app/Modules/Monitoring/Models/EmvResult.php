<?php

namespace App\Modules\Monitoring\Models;

use App\Shared\Casts\AsValueObject;
use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\ValueObjects\MetricValue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One calculated EMV figure for one ContentItem (REQ-M1-011). Append-only:
 * the row snapshots the formula version, rate-card version, currency,
 * inputs, and their tiers, so it remains reproducible and disclosable after
 * any configuration change (AC-M1-011). The value is a MetricValue at tier
 * ESTIMATED — MET-EMV is a "modeled monetary estimate", never a fact
 * (DP-001).
 *
 * FLAGGED DEVIATION: not a canonical ENT-* — awaiting a data-model doc
 * amendment (see the create_emv_tables migration).
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $content_item_id
 * @property int $emv_configuration_id
 * @property string $formula_version
 * @property string $rate_card_version
 * @property string $currency
 * @property MetricValue $value
 * @property list<array<string, mixed>> $inputs
 * @property array<string, mixed>|null $assumptions
 * @property CarbonImmutable $calculated_at
 */
class EmvResult extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'content_item_id',
        'emv_configuration_id',
        'formula_version',
        'rate_card_version',
        'currency',
        'value',
        'inputs',
        'assumptions',
        'calculated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => AsValueObject::class.':'.MetricValue::class,
            'inputs' => 'array',
            'assumptions' => 'array',
            'calculated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('emv_results is append-only: past EMV values stay reproducible (AC-M1-011).');
        });

        static::deleting(function (): never {
            throw new LogicException('emv_results is append-only: past EMV values stay reproducible (AC-M1-011).');
        });
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /** @return BelongsTo<EmvConfiguration, $this> */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(EmvConfiguration::class, 'emv_configuration_id');
    }
}
