<?php

namespace App\Platform\Export\Jobs;

use App\Platform\Export\Contracts\ExportService;
use App\Platform\Export\DefaultExportService;
use App\Platform\Export\Models\ExportJob;
use App\Platform\Export\ReportBuilder;
use App\Platform\Export\ReportFilters;
use App\Platform\Export\Support\ExportJobStatus;
use App\Shared\Audit\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Renders one queued export: rollup-backed report → private artifact →
 * COMPLETED with an expiry. Errors persist a sanitized message only —
 * never report content or payloads (privacy-safe logging).
 */
class GenerateExportJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $exportJobId) {}

    public function handle(ReportBuilder $builder, ExportService $exporter): void
    {
        $job = ExportJob::query()->find($this->exportJobId);

        if ($job === null || $job->status !== ExportJobStatus::Pending) {
            return; // already handled (duplicate-safe)
        }

        $job->update(['status' => ExportJobStatus::Running]);

        try {
            $document = $builder->build($job->report, ReportFilters::validate($job->filters));

            $path = $exporter->export($job->format, $document->toArray());
            $disk = DefaultExportService::disk();

            $job->update([
                'status' => ExportJobStatus::Completed,
                'disk' => $disk,
                'file_path' => $path,
                'file_size' => Storage::disk($disk)->size($path),
                'completed_at' => now(),
                'expires_at' => now()->addHours((int) config('qds.exports.ttl_hours', 24)),
            ]);

            // System-side audit trail (job runs without an authenticated
            // user, so this is recorded directly with actor = requester).
            AuditLog::create([
                'user_id' => $job->user_id,
                'action' => 'export.completed',
                'subject_type' => $job->getMorphClass(),
                'subject_id' => $job->getKey(),
                'context' => ['actor_id' => $job->user_id, 'format' => $job->format->value, 'report' => $job->report],
                'request_id' => $job->correlation_id,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            $job->update([
                'status' => ExportJobStatus::Failed,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'failed_at' => now(),
            ]);

            Log::error('Export generation failed.', [
                'export_job_id' => $job->id,
                'correlation_id' => $job->correlation_id,
                'exception_class' => $e::class,
            ]);

            throw $e;
        }
    }
}
