<?php

namespace App\Platform\Ingestion\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A limited, REDACTED sample of one provider response kept briefly for
 * debugging (External API Monitoring: configurable per provider, redacted
 * before storage, short retention, restricted to authorized technical
 * users via ProviderResponseSamplePolicy). Operational infrastructure,
 * not an ENT-*.
 *
 * @property int $id
 * @property string $source
 * @property string $operation
 * @property string $correlation_id
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $sampled_at
 * @property CarbonImmutable $expires_at
 */
class ProviderResponseSample extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'source',
        'operation',
        'correlation_id',
        'payload',
        'sampled_at',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sampled_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
