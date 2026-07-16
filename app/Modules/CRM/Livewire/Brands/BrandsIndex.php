<?php

namespace App\Modules\CRM\Livewire\Brands;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Client;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\SectorLabel;
use App\Shared\Livewire\Concerns\WithDataTable;
use App\Shared\Tenancy\TenantRule;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Brands index (REQ-M3-005) — a brand belongs to a client and is the
 * primary aggregation dimension; its aliases feed monitoring subjects.
 * UsersIndex reference CRUD pattern (ADR-0012).
 */
class BrandsIndex extends Component
{
    use WithDataTable;

    // --- create/edit form state ---
    public bool $showForm = false;

    public ?int $editingBrandId = null;

    public string $brand_client_id = '';

    public string $brand_name = '';

    public string $brand_sector = '';

    /** One alias per line (ENT-Brand.aliases — list of string). */
    public string $brand_aliases = '';

    // --- delete confirmation state ---
    public ?int $confirmingDeleteId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Brand::class);

        if ($this->sortField === '') {
            $this->sortField = 'name';
        }
    }

    protected function sortableColumns(): array
    {
        return ['name', 'created_at'];
    }

    protected function currentPageIds(): array
    {
        return $this->brandsQuery()->paginate($this->perPage())->pluck('id')->all();
    }

    /** @return Builder<Brand> */
    protected function brandsQuery(): Builder
    {
        return $this->applySort(
            Brand::query()
                ->with('client')
                ->withCount('products')
                ->when($this->search !== '', function (Builder $query) {
                    $query->where('name', 'ilike', '%'.$this->search.'%');
                })
        );
    }

    // --- create / edit -----------------------------------------------------

    public function create(): void
    {
        $this->authorize('create', Brand::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $brandId): void
    {
        $brand = Brand::findOrFail($brandId);

        $this->authorize('update', $brand);

        $this->resetForm();
        $this->editingBrandId = $brand->id;
        $this->brand_client_id = (string) $brand->client_id;
        $this->brand_name = $brand->name;
        $this->brand_sector = $brand->sector->value ?? '';
        $this->brand_aliases = implode("\n", $brand->aliases ?? []);
        $this->showForm = true;
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'brand_client_id' => 'client',
            'brand_name' => 'name',
            'brand_sector' => 'sector',
            'brand_aliases' => 'aliases',
        ];
    }

    public function save(AuditLogger $audit): void
    {
        $editing = $this->editingBrandId !== null;
        $brand = $editing ? Brand::findOrFail($this->editingBrandId) : null;

        $this->authorize($editing ? 'update' : 'create', $brand ?? Brand::class);

        $validated = $this->validate([
            'brand_client_id' => ['required', 'integer', TenantRule::exists('clients', 'id')],
            'brand_name' => ['required', 'string', 'max:255'],
            'brand_sector' => ['nullable', Rule::in(array_column(SectorLabel::cases(), 'value'))],
            'brand_aliases' => ['nullable', 'string', 'max:5000'],
        ]);

        $attributes = [
            'client_id' => (int) $validated['brand_client_id'],
            'name' => $validated['brand_name'],
            'sector' => ($validated['brand_sector'] ?? '') !== '' ? SectorLabel::from($validated['brand_sector']) : null,
            'aliases' => $this->parseLines($validated['brand_aliases'] ?? ''),
        ];

        if ($editing) {
            $brand->update($attributes);
        } else {
            $brand = Brand::create($attributes);
        }

        $audit->record($editing ? 'brand.updated' : 'brand.created', $brand, ['name' => $brand->name]);

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', type: 'success', message: $editing ? 'Brand updated.' : 'Brand created.');
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    // --- delete ------------------------------------------------------------

    public function confirmDelete(int $brandId): void
    {
        $this->authorize('delete', Brand::findOrFail($brandId));

        $this->confirmingDeleteId = $brandId;
    }

    public function delete(AuditLogger $audit): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $brand = Brand::findOrFail($this->confirmingDeleteId);

        $this->authorize('delete', $brand);

        try {
            // Savepoint so a restrict-FK refusal leaves the connection usable.
            DB::transaction(fn () => $brand->delete());
        } catch (QueryException) {
            $this->confirmingDeleteId = null;
            $this->dispatch('notify', type: 'error', message: 'Cannot delete: this brand still has products, campaigns, or monitoring records.');

            return;
        }

        $audit->record('brand.deleted', $brand, ['name' => $brand->name]);

        $this->confirmingDeleteId = null;
        $this->clearSelection();
        $this->clampPage();
        $this->dispatch('notify', type: 'success', message: 'Brand deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    /** After deletes/filter-affecting mutations, leave no out-of-range page. */
    protected function clampPage(): void
    {
        if ($this->getPage() > 1 && $this->brandsQuery()->paginate($this->perPage())->isEmpty()) {
            $this->resetPage();
        }
    }

    // -------------------------------------------------------------------------

    /** @return list<string> */
    protected function parseLines(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingBrandId = null;
        $this->brand_client_id = '';
        $this->brand_name = '';
        $this->brand_sector = '';
        $this->brand_aliases = '';
    }

    public function render(): View
    {
        return view('livewire.crm.brands-index', [
            'brands' => $this->brandsQuery()->paginate($this->perPage()),
            'clients' => Client::orderBy('name')->get(),
            'sectors' => SectorLabel::cases(),
        ]);
    }
}
