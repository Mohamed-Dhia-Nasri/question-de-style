<?php

namespace App\Platform\Ingestion\Models;

use App\Platform\Ingestion\Support\AlertType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A deduplicated ingestion alert (repeated failures, schema drift, stale
 * data, abnormal duration, excessive retries, story-polling risk). One OPEN
 * row per (type, source) fingerprint; recurrences bump `count` instead of
 * flooding. Messages are sanitized before they get here. Operational
 * infrastructure, not an ENT-*.
 *
 * tenant_id is OPTIONAL (ADR-0019): provider-level incidents stay global
 * (NULL); the per-tenant data-quality scan stamps the owning tenant so the
 * operations dashboard shows an operator only their own roster alerts.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property AlertType $alert_type
 * @property string|null $source
 * @property string $fingerprint
 * @property string $severity
 * @property string $message
 * @property int $count
 * @property CarbonImmutable $first_occurred_at
 * @property CarbonImmutable $last_occurred_at
 * @property CarbonImmutable|null $resolved_at
 */
class IngestionAlert extends Model
{
    protected $fillable = [
        'tenant_id',
        'alert_type',
        'source',
        'fingerprint',
        'severity',
        'message',
        'count',
        'first_occurred_at',
        'last_occurred_at',
        'resolved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'alert_type' => AlertType::class,
            'first_occurred_at' => 'immutable_datetime',
            'last_occurred_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
