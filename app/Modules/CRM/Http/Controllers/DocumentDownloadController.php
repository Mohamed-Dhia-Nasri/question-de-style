<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Modules\CRM\Models\DocumentAttachment;
use App\Shared\Audit\AuditLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a stored document attachment to its authorized requester
 * (REQ-M3-010, AC-M3-016). Defense in depth — the ExportDownloadController
 * precedent: authenticated session + DocumentAttachmentPolicy + a valid,
 * unexpired signature. The private qds.documents disk is never exposed
 * through a public URL (spec D7).
 */
class DocumentDownloadController
{
    use AuthorizesRequests;

    public function __invoke(Request $request, DocumentAttachment $documentAttachment, AuditLogger $audit): StreamedResponse
    {
        $this->authorize('view', $documentAttachment);

        $disk = Storage::disk((string) config('qds.documents.disk', 'local'));

        abort_unless($disk->exists($documentAttachment->storage_url), 404, 'The stored document is missing.');

        // Ids only in the immutable audit context (deep-review L2): the
        // client-supplied file name may carry personal data and audit rows
        // survive a GDPR erasure; subject_id identifies the document.
        $audit->record('document.downloaded', $documentAttachment, []);

        return $disk->download($documentAttachment->storage_url, $documentAttachment->file_name);
    }
}
