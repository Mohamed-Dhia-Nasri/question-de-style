<?php

namespace App\Platform\Export;

use App\Models\User;
use App\Platform\Export\Jobs\GenerateExportJob;
use App\Platform\Export\Models\ExportJob;
use App\Platform\Export\Support\ExportJobStatus;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\ExportFormat;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates the SVC-Export job lifecycle: validated request →
 * duplicate-safe ledger row → queued render → signed, expiring download →
 * pruning. Every step is audited without recording report content
 * (identifiers and filter codes only — DP-005).
 */
class ExportManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $filters
     *
     * @throws ValidationException
     */
    public function request(User $user, string $report, ExportFormat $format, array $filters): ExportJob
    {
        if (! in_array($report, ReportBuilder::reports(), true)) {
            throw ValidationException::withMessages(['report' => 'Unknown report.']);
        }

        $validated = ReportFilters::validate($filters);

        // Duplicate prevention: an identical request that is still live
        // collapses onto the existing job (the partial unique index backs
        // this check against races).
        $live = $this->liveDuplicate($user, $report, $format, $validated->hash());

        if ($live !== null) {
            return $live;
        }

        try {
            // ADR-0019: requests arrive over HTTP with the tenant context set
            // by middleware; ExportJob's BelongsToTenant stamps tenant_id on
            // create (NOT NULL backstop if no context — never guessed).
            $job = ExportJob::query()->create([
                'user_id' => $user->id,
                'report' => $report,
                'format' => $format,
                'filters' => $validated->toArray(),
                'filters_hash' => $validated->hash(),
                'status' => ExportJobStatus::Pending,
                'correlation_id' => (string) request()->attributes->get('request_id', str()->uuid()),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost a race against a concurrent identical request.
            return $this->liveDuplicate($user, $report, $format, $validated->hash())
                ?? throw ValidationException::withMessages(['report' => 'An identical export just finished — request it again.']);
        }

        $this->audit->record('export.requested', $job, [
            'report' => $report,
            'format' => $format->value,
            'filters' => $validated->toArray(),
        ]);

        GenerateExportJob::dispatch($job->id);

        return $job;
    }

    private function liveDuplicate(User $user, string $report, ExportFormat $format, string $hash): ?ExportJob
    {
        return ExportJob::query()
            ->where('user_id', $user->id)
            ->where('report', $report)
            ->where('format', $format->value)
            ->where('filters_hash', $hash)
            ->whereIn('status', [ExportJobStatus::Pending->value, ExportJobStatus::Running->value])
            ->first();
    }

    /** Short-lived signed download URL (never a public/static URL). */
    public function downloadUrl(ExportJob $job): string
    {
        return URL::temporarySignedRoute(
            'exports.download',
            now()->addMinutes((int) config('qds.exports.download_link_ttl_minutes', 10)),
            ['exportJob' => $job->id],
        );
    }

    /** Delete expired artifacts and close their ledger rows (DP-005 retention). */
    public function pruneExpired(): int
    {
        $expired = ExportJob::query()
            ->where('status', ExportJobStatus::Completed)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expired as $job) {
            // Runs from the scheduler in platform (null) context — attribute
            // each pruned job's audit event to its owning tenant (ADR-0019).
            app(TenantContext::class)->runAs($job->tenant_id, function () use ($job): void {
                if ($job->disk !== null && $job->file_path !== null) {
                    Storage::disk($job->disk)->delete($job->file_path);
                }

                $job->update(['status' => ExportJobStatus::Expired, 'file_path' => null]);

                $this->audit->record('export.pruned', $job);
            });
        }

        return $expired->count();
    }
}
