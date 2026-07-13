<?php

namespace App\Platform\Export\Models;

use App\Models\User;
use App\Platform\Export\Support\ExportJobStatus;
use App\Shared\Enums\ExportFormat;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ExportJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One SVC-Export job: a request for a rollup-backed report in one
 * ENUM-ExportFormat, rendered into private expiring storage (REQ-M1-012).
 *
 * FLAGGED DEVIATION: operational table, not a canonical ENT-* (see the
 * create_export_jobs migration).
 *
 * Tenant-owned (ADR-0019): NOT NULL tenant_id, scoped and stamped via BelongsToTenant.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $user_id
 * @property string $report
 * @property ExportFormat $format
 * @property array<string, mixed> $filters
 * @property string $filters_hash
 * @property ExportJobStatus $status
 * @property string|null $disk
 * @property string|null $file_path
 * @property int|null $file_size
 * @property string $correlation_id
 * @property string|null $error
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $expires_at
 */
class ExportJob extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ExportJobFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'report',
        'format',
        'filters',
        'filters_hash',
        'status',
        'disk',
        'file_path',
        'file_size',
        'correlation_id',
        'error',
        'completed_at',
        'failed_at',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'format' => ExportFormat::class,
            'filters' => 'array',
            'status' => ExportJobStatus::class,
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDownloadable(): bool
    {
        return $this->status === ExportJobStatus::Completed
            && $this->file_path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
