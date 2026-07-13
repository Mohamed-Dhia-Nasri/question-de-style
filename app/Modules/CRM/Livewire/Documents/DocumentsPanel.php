<?php

namespace App\Modules\CRM\Livewire\Documents;

use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\DocumentAttachment;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Documents panel (REQ-M3-010, AC-M3-016) — one reusable component
 * mounted with EXACTLY one parent: `creator:` on the creator profile,
 * `campaign:` on the campaign detail, `seedingCampaign:` on the seeding
 * detail (the seeding anchor is spec D6 — flagged deviation from the
 * canonical ENT shape, awaiting a doc amendment).
 *
 * Blobs live on the PRIVATE qds.documents disk under documents/ with a
 * random stored name (the SVC-Export precedent) and are served only via
 * the short-lived signed crm.documents.download route — never a public
 * URL. Size cap + extension allowlist are operational choices (spec D7).
 * Uploader identity lives in the audit row (document.uploaded /
 * document.deleted) — canon has no uploadedBy field.
 */
class DocumentsPanel extends Component
{
    use WithFileUploads;

    public ?Creator $creator = null;

    public ?Campaign $campaign = null;

    public ?SeedingCampaign $seedingCampaign = null;

    // --- upload form state ---
    public bool $showForm = false;

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    // --- delete confirmation state ---
    public ?int $confirmingDeleteId = null;

    /** Extension allowlist (spec D7 — operational choice, flagged). */
    private const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'png', 'jpg', 'jpeg', 'zip'];

    /**
     * The parent arrives as a Livewire prop (assigned to the matching public
     * property before mount) — NOT as a mount() parameter: container method
     * injection would materialize empty models for the absent nullable
     * parents and break the exactly-one check.
     */
    public function mount(): void
    {
        $parents = array_filter([$this->creator, $this->campaign, $this->seedingCampaign]);

        if (count($parents) !== 1) {
            throw new InvalidArgumentException(
                'DocumentsPanel must be mounted with exactly one parent (creator, campaign, or seedingCampaign).',
            );
        }

        $this->authorize('view', array_values($parents)[0]);
    }

    // --- upload ------------------------------------------------------------

    public function openForm(): void
    {
        $this->authorize('create', DocumentAttachment::class);

        $this->resetValidation();
        $this->upload = null;
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('create', DocumentAttachment::class);

        $this->validate([
            // 10 MB cap + extension allowlist (spec D7, operational choice).
            'upload' => ['required', 'file', 'max:10240', 'extensions:'.implode(',', self::ALLOWED_EXTENSIONS)],
        ]);

        // Random stored name on the private disk (export precedent) — the
        // client name is kept as display metadata only, never as the path.
        // ADR-0019: new uploads live under a per-tenant prefix (HTTP always
        // carries a tenant context; fail loudly BEFORE storing the blob so a
        // context-less request leaves no orphan file). Existing rows keep
        // their stored paths — downloads use storage_url from the row.
        $tenantId = app(TenantContext::class)->idOrFail();

        $path = Storage::disk(self::disk())->putFile(
            "tenants/{$tenantId}/documents/".now()->format('Y/m'),
            $this->upload,
        );

        if ($path === false) {
            throw new RuntimeException('The document blob could not be stored.');
        }

        $document = DocumentAttachment::create([
            'creator_id' => $this->creator?->id,
            'campaign_id' => $this->campaign?->id,
            'seeding_campaign_id' => $this->seedingCampaign?->id,
            'file_name' => $this->upload->getClientOriginalName(),
            'storage_url' => $path,
            'uploaded_at' => now(),
        ]);

        // Ids only in the immutable audit context (deep-review L2): the
        // client-supplied file name may carry personal data and audit rows
        // survive a GDPR erasure; subject_id already identifies the row.
        $audit->record('document.uploaded', $document, [
            'creator_id' => $document->creator_id,
            'campaign_id' => $document->campaign_id,
            'seeding_campaign_id' => $document->seeding_campaign_id,
        ]);

        $this->showForm = false;
        $this->upload = null;
        $this->dispatch('notify', type: 'success', message: 'Document uploaded.');
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->upload = null;
        $this->resetValidation();
    }

    // --- delete ------------------------------------------------------------

    public function confirmDelete(int $documentId): void
    {
        $this->authorize('delete', $this->documentsQuery()->findOrFail($documentId));

        $this->confirmingDeleteId = $documentId;
    }

    public function delete(AuditLogger $audit): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $document = $this->documentsQuery()->findOrFail($this->confirmingDeleteId);

        $this->authorize('delete', $document);

        // Row + blob go together: a failed blob delete rolls the row back.
        // FilesystemAdapter::delete() reports failure as false rather than
        // throwing, so surface it (an already-missing blob is not a failure).
        // The audit entry is written LAST, inside the same transaction, so
        // it can never assert a deletion that rolled back (deep-review L3);
        // the context carries ids only — a client-supplied file name in the
        // append-only log would defeat GDPR erasure (deep-review L2).
        DB::transaction(function () use ($document, $audit) {
            $document->delete();

            $disk = Storage::disk(self::disk());

            if (! $disk->delete($document->storage_url) && $disk->exists($document->storage_url)) {
                throw new RuntimeException('The stored document blob could not be deleted.');
            }

            $audit->record('document.deleted', $document, [
                'creator_id' => $document->creator_id,
                'campaign_id' => $document->campaign_id,
                'seeding_campaign_id' => $document->seeding_campaign_id,
            ]);
        });

        $this->confirmingDeleteId = null;
        $this->dispatch('notify', type: 'success', message: 'Document deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    // -------------------------------------------------------------------------

    /** Short-lived signed download URL (never a public/static URL). */
    public function downloadUrl(DocumentAttachment $document): string
    {
        return URL::temporarySignedRoute(
            'crm.documents.download',
            now()->addMinutes((int) config('qds.documents.download_link_ttl_minutes', 10)),
            ['documentAttachment' => $document->id],
        );
    }

    private static function disk(): string
    {
        return (string) config('qds.documents.disk', 'local');
    }

    /** @return Builder<DocumentAttachment> */
    private function documentsQuery(): Builder
    {
        return DocumentAttachment::query()
            ->when($this->creator !== null, fn (Builder $query) => $query->where('creator_id', $this->creator->id))
            ->when($this->campaign !== null, fn (Builder $query) => $query->where('campaign_id', $this->campaign->id))
            ->when($this->seedingCampaign !== null, fn (Builder $query) => $query->where('seeding_campaign_id', $this->seedingCampaign->id));
    }

    public function render(): View
    {
        $disk = Storage::disk(self::disk());
        $documents = $this->documentsQuery()->orderByDesc('uploaded_at')->get();

        return view('livewire.crm.documents-panel', [
            'documents' => $documents,
            // Size shown only when the blob is cheaply readable from the disk.
            'sizes' => $documents->mapWithKeys(fn (DocumentAttachment $document) => [
                $document->id => $disk->exists($document->storage_url) ? $disk->size($document->storage_url) : null,
            ]),
        ]);
    }
}
