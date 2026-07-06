<?php

namespace App\Platform\Ingestion\Models;

use App\Platform\Ingestion\Support\ErrorCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One invalid provider record held out of the domain tables ("Reject or
 * quarantine records lacking valid provenance / failing validation —
 * never silently stored"). The payload is REDACTED before persistence (no
 * credentials, personal data, media, or private URLs) and expires with the
 * quarantine retention window. Operational infrastructure, not an ENT-*.
 *
 * @property int $id
 * @property string $source
 * @property string $operation
 * @property string $correlation_id
 * @property string|null $external_hint
 * @property ErrorCategory $reason_category
 * @property string $reason
 * @property array<array-key, mixed> $payload
 * @property CarbonImmutable $expires_at
 */
class QuarantinedRecord extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'source',
        'operation',
        'correlation_id',
        'external_hint',
        'reason_category',
        'reason',
        'payload',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason_category' => ErrorCategory::class,
            'payload' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
