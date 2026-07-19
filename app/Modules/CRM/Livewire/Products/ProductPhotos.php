<?php

namespace App\Modules\CRM\Livewire\Products;

use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Platform\Enrichment\VisualMatch\Contracts\EmbeddingProvider;
use App\Platform\Enrichment\VisualMatch\Jobs\EmbedProductPhotoJob;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\PhotoViewLabel;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

/**
 * Manage-photos modal for /crm/products (spec §6): reference photos are
 * the tenant's visual catalog for sub-project C. Blobs live on the
 * PRIVATE media disk under tenants/{tenant}/product-photos/{product}/
 * and are served ONLY via the short-TTL signed crm.products.photo route
 * (the documents precedent). Mutations require the same authorization as
 * product edit (ProductPolicy::update) and are audited as
 * product.photo_added / product.photo_removed with ids-only context.
 */
class ProductPhotos extends Component
{
    use WithFileUploads;

    /** Product being managed; null = modal closed. */
    public ?int $productId = null;

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    public string $view_label = '';

    public ?int $confirmingDeleteId = null;

    #[On('open-product-photos')]
    public function open(int $productId): void
    {
        $product = Product::findOrFail($productId);

        $this->authorize('view', $product);

        $this->resetValidation();
        $this->upload = null;
        $this->view_label = '';
        $this->confirmingDeleteId = null;
        $this->productId = $product->id;
    }

    public function close(): void
    {
        $this->productId = null;
        $this->upload = null;
        $this->view_label = '';
        $this->confirmingDeleteId = null;
        $this->resetValidation();
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'upload' => 'photo',
            'view_label' => 'view label',
        ];
    }

    public function save(AuditLogger $audit): void
    {
        $product = Product::findOrFail($this->productId);

        $this->authorize('update', $product);

        $this->validate([
            // jpg/png/webp only in v1 (spec §4.1 — heic is model-supported
            // but renders in no browser); 10 MB mirrors the documents cap.
            'upload' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
            'view_label' => ['nullable', Rule::in(array_column(PhotoViewLabel::cases(), 'value'))],
        ]);

        $cap = (int) config('qds.enrichment.visual_match.photo_cap', 8);

        // Cheap pre-check so an obviously-over-cap request never touches the
        // disk. NOT the authoritative check (see the locked transaction
        // below) — this alone would still let two concurrent saves at
        // cap-1 both pass.
        if (ProductReferencePhoto::query()->where('product_id', $product->id)->count() >= $cap) {
            $this->addError('upload', "This product already has the maximum of {$cap} photos.");

            return;
        }

        // ADR-0019: fail loudly BEFORE storing the blob so a context-less
        // request leaves no orphan file (the DocumentsPanel precedent).
        $tenantId = app(TenantContext::class)->idOrFail();

        $bytes = (string) $this->upload->get();

        // Content-sniffed extension — never the client-supplied filename (a
        // real JPEG uploaded as "photo.html" must still store as .jpg). The
        // `mimes:` rule above already validated guessExtension() resolves
        // to jpg/jpeg/png/webp, so no fallback is needed here. jpeg -> jpg
        // matches the keyframe-storage convention (KeyframeExtractor::extensionFor).
        $extension = match ($this->upload->guessExtension()) {
            'jpeg' => 'jpg',
            default => $this->upload->guessExtension(),
        };
        $disk = (string) config('qds.ingestion.media_disk', 'media');
        $path = "tenants/{$tenantId}/product-photos/{$product->id}/".Str::uuid().'.'.$extension;

        if (! Storage::disk($disk)->put($path, $bytes)) {
            throw new RuntimeException('The product photo blob could not be stored.');
        }

        // Best-effort dimensions (spec §4.1) — a corrupt-but-valid-mime
        // upload stores with null width/height rather than failing.
        $dimensions = @getimagesizefromstring($bytes) ?: null;
        $viewLabel = $this->view_label !== '' ? $this->view_label : null;

        // Authoritative cap enforcement: lock the product row so two
        // concurrent saves can never both read "count < cap" and both
        // insert (closes the TOCTOU race the pre-check above cannot). A
        // losing save's blob (already stored above) is cleaned up below —
        // storing the blob only after a successful insert would instead
        // require assigning storage_path before the bytes exist on disk,
        // which trades an orphan blob for a dangling row (worse: rows are
        // what matching/audit/UI depend on).
        try {
            $photo = DB::transaction(function () use ($product, $cap, $disk, $path, $bytes, $dimensions, $viewLabel, $audit): ?ProductReferencePhoto {
                Product::query()->whereKey($product->id)->lockForUpdate()->first();

                if (ProductReferencePhoto::query()->where('product_id', $product->id)->count() >= $cap) {
                    return null;
                }

                $photo = ProductReferencePhoto::create([
                    'product_id' => $product->id,
                    'storage_disk' => $disk,
                    'storage_path' => $path,
                    'view_label' => $viewLabel,
                    'checksum' => hash('sha256', $bytes),
                    'width' => $dimensions[0] ?? null,
                    'height' => $dimensions[1] ?? null,
                    'uploaded_by' => Auth::id(),
                ]);

                // Ids only in the immutable audit context (house rule).
                $audit->record('product.photo_added', $photo, ['product_id' => $product->id]);

                return $photo;
            });
        } catch (Throwable $e) {
            // A failure inside the transaction (constraint violation, lock
            // timeout, deadlock — DB::transaction's default attempts=1
            // rethrows immediately rather than retrying) must not leave the
            // already-stored blob orphaned on disk.
            Storage::disk($disk)->delete($path);

            throw $e;
        }

        if ($photo === null) {
            Storage::disk($disk)->delete($path);
            $this->addError('upload', "This product already has the maximum of {$cap} photos.");

            return;
        }

        // Embed now only when the capability can actually spend (spec §6);
        // otherwise qds:embed-product-photos picks the photo up later.
        if ((bool) config('qds.enrichment.visual_match.enabled')
            && app(EmbeddingProvider::class)->isConfigured()) {
            EmbedProductPhotoJob::dispatch($photo->id);
        }

        $this->upload = null;
        $this->view_label = '';
        $this->dispatch('product-photos-changed');
        $this->dispatch('notify', type: 'success', message: 'Photo added.');
    }

    // --- delete ------------------------------------------------------------

    public function confirmDelete(int $photoId): void
    {
        $product = Product::findOrFail($this->productId);

        $this->authorize('update', $product);

        $this->confirmingDeleteId = ProductReferencePhoto::query()
            ->where('product_id', $product->id)
            ->findOrFail($photoId)
            ->id;
    }

    public function deletePhoto(AuditLogger $audit): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $product = Product::findOrFail($this->productId);

        $this->authorize('update', $product);

        $photo = ProductReferencePhoto::query()
            ->where('product_id', $product->id)
            ->findOrFail($this->confirmingDeleteId);

        // House order (spec §6): the row (+ embedding rows via DB cascade)
        // goes in the transaction; the blob goes AFTER commit — a rolled-
        // back delete must leave the file in place, and an orphan blob is
        // recoverable where a dangling row is not.
        DB::transaction(function () use ($photo, $product, $audit): void {
            $photo->delete();

            $audit->record('product.photo_removed', $photo, ['product_id' => $product->id]);
        });

        Storage::disk((string) $photo->storage_disk)->delete((string) $photo->storage_path);

        $this->confirmingDeleteId = null;
        $this->dispatch('product-photos-changed');
        $this->dispatch('notify', type: 'success', message: 'Photo deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    // -------------------------------------------------------------------------

    /** Short-TTL signed thumbnail URL (never a public/static URL). */
    public function thumbnailUrl(ProductReferencePhoto $photo): string
    {
        return URL::temporarySignedRoute(
            'crm.products.photo',
            now()->addMinutes((int) config('qds.enrichment.visual_match.photo_link_ttl_minutes', 10)),
            ['productReferencePhoto' => $photo->id],
        );
    }

    public function render(): View
    {
        $product = $this->productId !== null ? Product::query()->find($this->productId) : null;

        return view('livewire.crm.product-photos', [
            'product' => $product,
            'photos' => $product !== null
                ? ProductReferencePhoto::query()->where('product_id', $product->id)->orderBy('id')->get()
                : collect(),
            'photoCap' => (int) config('qds.enrichment.visual_match.photo_cap', 8),
            'viewLabels' => PhotoViewLabel::cases(),
        ]);
    }
}
