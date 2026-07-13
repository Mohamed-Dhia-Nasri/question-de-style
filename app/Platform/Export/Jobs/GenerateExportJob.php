<?php

namespace App\Platform\Export\Jobs;

use App\Platform\Export\Contracts\ExportService;
use App\Platform\Export\DefaultExportService;
use App\Platform\Export\Models\ExportJob;
use App\Platform\Export\ReportBuilder;
use App\Platform\Export\ReportFilters;
use App\Platform\Export\Support\ExportJobStatus;
use App\Shared\Audit\AuditLog;
use App\Shared\Tenancy\TenantContext;
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

        // ADR-0019: the dispatcher's tenant travels in the queue payload and
        // is restored automatically before handle() runs. Fallback for jobs
        // enqueued without a context (e.g. re-dispatched from console): run
        // under the ExportJob row's own tenant so the ReportBuilder's rollup
        // reads and the artifact path are tenant-scoped.
        $context = app(TenantContext::class);

        if ($context->id() === null) {
            $context->runAs($job->tenant_id, fn () => $this->generate($job, $builder, $exporter));

            return;
        }

        $this->generate($job, $builder, $exporter);
    }

    private function generate(ExportJob $job, ReportBuilder $builder, ExportService $exporter): void
    {
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
            // tenant_id/user_id are force-filled from the job row, never mass
            // assigned — the audit trail's ownership stamp is trust-critical
            // and non-fillable by design (ADR-0019).
            $log = new AuditLog;
            $log->forceFill([
                'tenant_id' => $job->tenant_id,
                'user_id' => $job->user_id,
                'action' => 'export.completed',
                'subject_type' => $job->getMorphClass(),
                'subject_id' => $job->getKey(),
                'context' => ['actor_id' => $job->user_id, 'format' => $job->format->value, 'report' => $job->report],
                'request_id' => $job->correlation_id,
                'created_at' => now(),
            ]);
            $log->save();
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
