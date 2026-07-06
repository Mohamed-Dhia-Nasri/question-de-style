<?php

namespace App\Platform\Ingestion\Models;

use App\Platform\Ingestion\Support\CycleStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One monitoring cycle over the roster (AC-M1-001): fan-out bookkeeping,
 * duplicate-cycle prevention, and total-cycle timing. Jobs decrement
 * `jobs_pending` as they finish; the cycle completes when it reaches zero.
 * Operational infrastructure, not an ENT-*.
 *
 * @property int $id
 * @property string $correlation_id
 * @property CycleStatus $status
 * @property bool $stories_only
 * @property int $accounts_count
 * @property int $jobs_expected
 * @property int $jobs_pending
 * @property int $jobs_failed
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
class IngestionCycle extends Model
{
    protected $fillable = [
        'correlation_id',
        'status',
        'stories_only',
        'accounts_count',
        'jobs_expected',
        'jobs_pending',
        'jobs_failed',
        'started_at',
        'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CycleStatus::class,
            'stories_only' => 'boolean',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function isRunning(): bool
    {
        return $this->status === CycleStatus::Running;
    }
}
