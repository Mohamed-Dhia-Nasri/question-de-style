<?php

namespace App\Modules\Monitoring\Models;

use App\Modules\CRM\Models\PlatformAccount;
use App\Shared\Casts\AsValueObject;
use App\Shared\Casts\AsValueObjectCollection;
use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;
use Database\Factories\MetricSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * ENT-MetricSnapshot — a timestamped point in a metric time series; the sole
 * substrate for historical growth (docs/30-data-model/00-data-model.md#ent-metricsnapshot,
 * ADR-0003, REQ-M1-007).
 *
 * Write-owner: Module 1 Monitoring; produced by SVC-SnapshotScheduler on a
 * recurring schedule (ownership matrix). Account-level snapshots set
 * platform_account_id; content-level snapshots set content_item_id.
 * Externally sourced → mandatory Provenance (DP-002); every element of
 * `metrics` is a MetricValue carrying its own ENUM-MetricTier (DP-001).
 *
 * APPEND-ONLY: snapshots are immutable history — updates and deletes throw
 * here and are also rejected by a database trigger.
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $platform_account_id
 * @property int|null $content_item_id
 * @property CarbonImmutable $captured_at
 * @property list<MetricValue> $metrics
 * @property Provenance $provenance
 */
class MetricSnapshot extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MetricSnapshotFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'platform_account_id',
        'content_item_id',
        'captured_at',
        'metrics',
        'provenance',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('MetricSnapshot is append-only (ADR-0003): updates are not permitted.');
        });

        static::deleting(function (): never {
            throw new LogicException('MetricSnapshot is append-only (ADR-0003): deletes are not permitted.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'captured_at' => 'immutable_datetime',
            'metrics' => AsValueObjectCollection::class.':'.MetricValue::class,
            'provenance' => AsValueObject::class.':'.Provenance::class,
        ];
    }

    /** @return BelongsTo<PlatformAccount, $this> */
    public function platformAccount(): BelongsTo
    {
        return $this->belongsTo(PlatformAccount::class);
    }

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }
}
