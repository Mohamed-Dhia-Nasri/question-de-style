<?php

namespace App\Modules\CRM\Livewire\Products;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Product;
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
use Illuminate\Validation\Rule;
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
            'product_unit_value' => ['nullable', 'numeric', 'min:0'],
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

        try {
            // Savepoint so a restrict-FK refusal leaves the connection usable.
            DB::transaction(fn () => $product->delete());
        } catch (QueryException) {
            $this->confirmingDeleteId = null;
            $this->dispatch('notify', type: 'error', message: 'Cannot delete: this product is referenced by seeding campaigns or shipments.');

            return;
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
            'categories' => SectorLabel::cases(),
        ]);
    }
}
