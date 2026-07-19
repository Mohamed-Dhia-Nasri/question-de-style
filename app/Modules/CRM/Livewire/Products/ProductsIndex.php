<?php

namespace App\Modules\CRM\Livewire\Products;

use App\Modules\CRM\Livewire\Concerns\WithInlineCreate;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductReferencePhoto;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\SectorLabel;
use App\Shared\Livewire\Concerns\WithDataTable;
use App\Shared\Tenancy\TenantRule;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Products index (REQ-M3-005) — the product/SKU under a brand is the
 * seeding aggregation key (REQ-M3-013). unitValue is a MetricValue at tier
 * CONFIRMED (manual agency input — glossary ENUM-MetricTier), and the tier
 * travels with the number in the UI (DP-001).
 */
class ProductsIndex extends Component
{
    use WithDataTable;
    use WithInlineCreate;

    // --- create/edit form state ---
    public bool $showForm = false;

    public ?int $editingProductId = null;

    public string $product_brand_id = '';

    public string $product_name = '';

    public string $product_sku = '';

    public string $product_variant = '';

    public string $product_unit_value = '';

    public string $product_category = '';

    // --- delete confirmation state ---
    public ?int $confirmingDeleteId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Product::class);

        if ($this->sortField === '') {
            $this->sortField = 'name';
        }
    }

    protected function sortableColumns(): array
    {
        return ['name', 'sku', 'created_at'];
    }

    protected function currentPageIds(): array
    {
        return $this->productsQuery()->paginate($this->perPage())->pluck('id')->all();
    }

    /** @return Builder<Product> */
    protected function productsQuery(): Builder
    {
        return $this->applySort(
            Product::query()
                ->with('brand')
                ->withCount('referencePhotos')
                ->when($this->search !== '', function (Builder $query) {
                    $query->where(function (Builder $query) {
                        $query->where('name', 'ilike', '%'.$this->search.'%')
                            ->orWhere('sku', 'ilike', '%'.$this->search.'%');
                    });
                })
        );
    }

    // --- create / edit -----------------------------------------------------

    public function create(): void
    {
        $this->authorize('create', Product::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $productId): void
    {
        $product = Product::findOrFail($productId);

        $this->authorize('update', $product);

        $this->resetForm();
        $this->editingProductId = $product->id;
        $this->product_brand_id = (string) $product->brand_id;
        $this->product_name = $product->name;
        $this->product_sku = $product->sku ?? '';
        $this->product_variant = $product->variant ?? '';
        $this->product_unit_value = $product->unit_value !== null ? (string) $product->unit_value->amount : '';
        $this->product_category = $product->category->value ?? '';
        $this->showForm = true;
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return array_merge([
            'product_brand_id' => 'brand',
            'product_name' => 'name',
            'product_sku' => 'SKU',
            'product_variant' => 'product variant',
            'product_unit_value' => 'unit value',
            'product_category' => 'sector',
        ], $this->inlineValidationAttributes());
    }

    /** @return list<string> */
    protected function inlineCreateTypes(): array
    {
        return ['brand'];
    }

    protected function inlineCreated(string $type, int $id): void
    {
        $this->product_brand_id = (string) $id;
    }

    public function save(AuditLogger $audit): void
    {
        $editing = $this->editingProductId !== null;
        $product = $editing ? Product::findOrFail($this->editingProductId) : null;

        $this->authorize($editing ? 'update' : 'create', $product ?? Product::class);

        $validated = $this->validate([
            'product_brand_id' => ['required', 'integer', TenantRule::exists('brands', 'id')],
            'product_name' => ['required', 'string', 'max:255'],
            'product_sku' => ['nullable', 'string', 'max:255'],
            'product_variant' => ['nullable', 'string', 'max:255'],
            'product_unit_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'product_category' => ['nullable', Rule::in(array_column(SectorLabel::cases(), 'value'))],
        ]);

        $attributes = [
            'brand_id' => (int) $validated['product_brand_id'],
            'name' => $validated['product_name'],
            'sku' => ($validated['product_sku'] ?? '') !== '' ? $validated['product_sku'] : null,
            'variant' => ($validated['product_variant'] ?? '') !== '' ? $validated['product_variant'] : null,
            // Manual agency input → tier CONFIRMED (glossary ENUM-MetricTier).
            'unit_value' => ($validated['product_unit_value'] ?? '') !== ''
                ? new MetricValue((float) $validated['product_unit_value'], MetricTier::Confirmed)
                : null,
            'category' => ($validated['product_category'] ?? '') !== '' ? SectorLabel::from($validated['product_category']) : null,
        ];

        if ($editing) {
            $product->update($attributes);
        } else {
            $product = Product::create($attributes);
        }

        $audit->record($editing ? 'product.updated' : 'product.created', $product, ['name' => $product->name]);

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', type: 'success', message: $editing ? 'Product updated.' : 'Product created.');
    }

    public function cancelForm(): void
    {
        if ($this->inlineCreate !== null) {
            $this->cancelInlineCreate();

            return;
        }

        $this->showForm = false;
        $this->resetForm();
    }

    // --- delete ------------------------------------------------------------

    public function confirmDelete(int $productId): void
    {
        $this->authorize('delete', Product::findOrFail($productId));

        $this->confirmingDeleteId = $productId;
    }

    public function delete(AuditLogger $audit): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $product = Product::findOrFail($this->confirmingDeleteId);

        $this->authorize('delete', $product);

        /** @var list<array{disk: string, path: string}> $blobs */
        $blobs = [];

        try {
            // Savepoint so a restrict-FK refusal leaves the connection
            // usable. Blob paths are collected INSIDE the transaction —
            // the photo rows are gone once the product cascade fires —
            // and the files are deleted only AFTER commit (spec §6, the
            // GDPR house order): a rolled-back delete must leave every
            // blob in place.
            DB::transaction(function () use ($product, &$blobs): void {
                $blobs = ProductReferencePhoto::query()
                    ->where('product_id', $product->id)
                    ->get(['storage_disk', 'storage_path'])
                    ->map(fn (ProductReferencePhoto $photo): array => [
                        'disk' => (string) $photo->storage_disk,
                        'path' => (string) $photo->storage_path,
                    ])
                    ->all();

                $product->delete();
            });
        } catch (QueryException) {
            $this->confirmingDeleteId = null;
            $this->dispatch('notify', type: 'error', message: 'Cannot delete: this product is referenced by seeding runs or shipments.');

            return;
        }

        // After commit: photo + embedding ROWS are already gone via the DB
        // cascade; the blobs go last, best-effort — an orphan file is
        // recoverable, a dangling row is not (the M31 ordering).
        foreach ($blobs as $blob) {
            Storage::disk($blob['disk'])->delete($blob['path']);
        }

        $audit->record('product.deleted', $product, ['name' => $product->name]);

        $this->confirmingDeleteId = null;
        $this->clearSelection();
        $this->clampPage();
        $this->dispatch('notify', type: 'success', message: 'Product deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    /** Photos-modal mutations re-render the list so the badge stays fresh. */
    #[On('product-photos-changed')]
    public function refreshPhotoCounts(): void
    {
        // Intentionally empty: receiving the event triggers a re-render,
        // which re-reads reference_photos_count.
    }

    /** After deletes/filter-affecting mutations, leave no out-of-range page. */
    protected function clampPage(): void
    {
        if ($this->getPage() > 1 && $this->productsQuery()->paginate($this->perPage())->isEmpty()) {
            $this->resetPage();
        }
    }

    // -------------------------------------------------------------------------

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingProductId = null;
        $this->product_brand_id = '';
        $this->product_name = '';
        $this->product_sku = '';
        $this->product_variant = '';
        $this->product_unit_value = '';
        $this->product_category = '';
    }

    public function render(): View
    {
        return view('livewire.crm.products-index', [
            'products' => $this->productsQuery()->paginate($this->perPage()),
            'brands' => Brand::orderBy('name')->get(),
            'clients' => Client::orderBy('name')->get(),
            'categories' => SectorLabel::cases(),
        ]);
    }
}
