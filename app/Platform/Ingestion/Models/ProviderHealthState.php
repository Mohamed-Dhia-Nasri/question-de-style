<?php

namespace App\Platform\Ingestion\Models;

use App\Platform\Ingestion\Support\ErrorCategory;
use App\Platform\Ingestion\Support\ProviderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Current health of one SRC-* provider, maintained after every call
 * (provider health view; P4 scraper-fragility groundwork, esp. TikTok —
 * ADR-0002). One row per provider. Operational infrastructure, not an ENT-*.
 *
 * @property int $id
 * @property string $source
 * @property ProviderStatus $status
 * @property CarbonImmutable|null $last_success_at
 * @property CarbonImmutable|null $last_failure_at
 * @property int $consecutive_failures
 * @property ErrorCategory|null $last_error_category
 * @property string|null $last_error_message
 */
class ProviderHealthState extends Model
{
    protected $fillable = [
        'source',
        'status',
        'last_success_at',
        'last_failure_at',
        'consecutive_failures',
        'last_error_category',
        'last_error_message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ProviderStatus::class,
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'last_error_category' => ErrorCategory::class,
        ];
    }
}
