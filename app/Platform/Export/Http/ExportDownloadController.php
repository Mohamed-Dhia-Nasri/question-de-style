<?php

namespace App\Platform\Export\Http;

use App\Platform\Export\DefaultExportService;
use App\Platform\Export\Models\ExportJob;
use App\Shared\Audit\AuditLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a completed export artifact to its authorized requester.
 * Defense in depth: authenticated session + ExportJobPolicy + a valid,
 * unexpired signature + artifact not past its expiry. The private disk is
 * never exposed through a public URL (REQ-M1-012 export security).
 */
class ExportDownloadController
{
    use AuthorizesRequests;

    public function __invoke(Request $request, ExportJob $exportJob, AuditLogger $audit): StreamedResponse
    {
        $this->authorize('download', $exportJob);

        abort_unless($exportJob->isDownloadable(), 410, 'This export has expired.');

        $audit->record('export.downloaded', $exportJob, [
            'format' => $exportJob->format->value,
            'report' => $exportJob->report,
        ]);

        $filename = sprintf(
            'qds-%s-%d.%s',
            $exportJob->report,
            $exportJob->id,
            DefaultExportService::extension($exportJob->format),
        );

        return Storage::disk((string) $exportJob->disk)
            ->download((string) $exportJob->file_path, $filename);
    }
}
