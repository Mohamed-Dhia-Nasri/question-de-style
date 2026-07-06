<?php

namespace App\Platform\Ingestion\Models;

use App\Platform\Ingestion\Support\CallOutcome;
use App\Platform\Ingestion\Support\ErrorCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Telemetry for ONE external provider call (External API Monitoring):
 * who was called, how long each pipeline stage took, what came back, and
 * what happened to every record (accepted / rejected / duplicate /
 * quarantined). Operational infrastructure, not a domain ENT-* — holds no
 * payload content, only sanitized metadata.
 *
 * @property int $id
 * @property string $source
 * @property string $operation
 * @property string $correlation_id
 * @property string|null $job_id
 * @property int|null $platform_account_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property float|null $duration_ms
 * @property int|null $http_status
 * @property CallOutcome $outcome
 * @property ErrorCategory|null $error_category
 * @property string|null $error_message
 * @property int $retry_count
 * @property int|null $response_bytes
 * @property int|null $result_count
 * @property int $accepted_count
 * @property int $rejected_count
 * @property int $duplicate_count
 * @property int $quarantined_count
 * @property array<string, mixed>|null $rate_limit
 * @property array<string, mixed>|null $timings
 */
class ProviderCall extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'source',
        'operation',
        'correlation_id',
        'job_id',
        'platform_account_id',
        'started_at',
        'finished_at',
        'duration_ms',
        'http_status',
        'outcome',
        'error_category',
        'error_message',
        'retry_count',
        'response_bytes',
        'result_count',
        'accepted_count',
        'rejected_count',
        'duplicate_count',
        'quarantined_count',
        'rate_limit',
        'timings',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'duration_ms' => 'float',
            'outcome' => CallOutcome::class,
            'error_category' => ErrorCategory::class,
            'rate_limit' => 'array',
            'timings' => 'array',
        ];
    }
}
