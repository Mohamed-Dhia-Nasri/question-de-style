<?php

namespace App\Modules\CRM\Livewire\Seeding;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\MetricTier;
use App\Shared\Enums\SeedingCampaignStatus;
use App\Shared\Enums\SeedingType;
use App\Shared\Livewire\Concerns\WithDataTable;
use App\Shared\Tenancy\TenantRule;
use App\Shared\ValueObjects\MetricValue;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Seeding campaigns index (REQ-M3-006) — AC-M3-010: every run records
 * exactly one of the four seeding variants and an ENUM-SeedingCampaignStatus
 * value (both closed-set validated); status transitions are recorded (same
 * convention as campaigns, AC-M3-009). The optional product must belong to
 * the run's brand (spec D5 coherence check). `spend` is the agency-entered
 * CONFIRMED MetricValue feeding CPE/CPM (AC-M3-015, spec §2.1/D1); its
 * changes ride the seeding_campaign.updated audit event.
 */
class SeedingCampaignsIndex extends Component
{
    use WithDataTable;

    #[Url(except: '')]
    public string $statusFilter = '';

    #[Url(except: '')]
    public string $typeFilter = '';

    // --- create/edit form state ---
    public bool $showForm = false;

    public ?int $editingSeedingId = null;

    public string $seeding_name = '';

    public string $seeding_type = '';

    public string $seeding_brand_id = '';

    public string $seeding_product_id = '';

    public string $seeding_campaign_id = '';

    public string $seeding_status = '';

    public string $seeding_spend = '';

    // --- delete confirmation state ---
    public ?int $confirmingDeleteId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', SeedingCampaign::class);

        if ($this->sortField === '') {
            $this->sortField = 'name';
        }
    }

    protected function sortableColumns(): array
    {
        return ['name', 'status', 'seeding_type', 'created_at'];
    }

    protected function currentPageIds(): array
    {
        return $this->seedingQuery()->paginate($this->perPage())->pluck('id')->all();
    }

    /** @return Builder<SeedingCampaign> */
    protected function seedingQuery(): Builder
    {
        return $this->applySort(
            SeedingCampaign::query()
                ->with(['brand', 'product', 'campaign'])
                ->withCount('shipments')
                ->when($this->search !== '', function (Builder $query) {
                    $query->where('name', 'ilike', '%'.$this->search.'%');
                })
                ->when($this->statusFilter !== '', function (Builder $query) {
                    if (SeedingCampaignStatus::tryFrom($this->statusFilter) !== null) {
                        $query->where('status', $this->statusFilter);
                    }
                })
                ->when($this->typeFilter !== '', function (Builder $query) {
                    if (SeedingType::tryFrom($this->typeFilter) !== null) {
                        $query->where('seeding_type', $this->typeFilter);
                    }
                })
        );
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatingTypeFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    // --- create / edit -----------------------------------------------------

    public function create(): void
    {
        $this->authorize('create', SeedingCampaign::class);

        $this->resetForm();
        $this->seeding_status = SeedingCampaignStatus::Draft->value;
        $this->showForm = true;
    }

    public function edit(int $seedingId): void
    {
        $seeding = SeedingCampaign::findOrFail($seedingId);

        $this->authorize('update', $seeding);

        $this->resetForm();
        $this->editingSeedingId = $seeding->id;
        $this->seeding_name = $seeding->name;
        $this->seeding_type = $seeding->seeding_type->value;
        $this->seeding_brand_id = (string) $seeding->brand_id;
        $this->seeding_product_id = $seeding->product_id !== null ? (string) $seeding->product_id : '';
        $this->seeding_campaign_id = $seeding->campaign_id !== null ? (string) $seeding->campaign_id : '';
        $this->seeding_status = $seeding->status->value;
        $this->seeding_spend = $seeding->spend !== null ? (string) $seeding->spend->amount : '';
        $this->showForm = true;
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'seeding_name' => 'name',
            'seeding_type' => 'seeding type',
            'seeding_brand_id' => 'brand',
            'seeding_product_id' => 'product',
            'seeding_campaign_id' => 'parent campaign',
            'seeding_status' => 'status',
            'seeding_spend' => 'spend',
        ];
    }

    public function save(AuditLogger $audit): void
    {
        $editing = $this->editingSeedingId !== null;
        $seeding = $editing ? SeedingCampaign::findOrFail($this->editingSeedingId) : null;

        $this->authorize($editing ? 'update' : 'create', $seeding ?? SeedingCampaign::class);

        $validated = $this->validate([
            'seeding_name' => ['required', 'string', 'max:255'],
            // AC-M3-010: exactly one of the four variants.
            'seeding_type' => ['required', Rule::in(array_column(SeedingType::cases(), 'value'))],
            'seeding_brand_id' => ['required', 'integer', TenantRule::exists('brands', 'id')],
            'seeding_product_id' => ['nullable', 'integer', TenantRule::exists('products', 'id')],
            'seeding_campaign_id' => ['nullable', 'integer', TenantRule::exists('campaigns', 'id')],
            'seeding_status' => ['required', Rule::in(array_column(SeedingCampaignStatus::cases(), 'value'))],
            'seeding_spend' => ['nullable', 'numeric', 'min:0'],
        ]);

        $productId = ($validated['seeding_product_id'] ?? '') !== '' ? (int) $validated['seeding_product_id'] : null;

        if ($productId !== null
            && Product::findOrFail($productId)->brand_id !== (int) $validated['seeding_brand_id']) {
            throw ValidationException::withMessages([
                'seeding_product_id' => 'The product must belong to the seeding run\'s brand.',
            ]);
        }

        $campaignId = ($validated['seeding_campaign_id'] ?? '') !== '' ? (int) $validated['seeding_campaign_id'] : null;

        // Deep-review finding M1: a run linked to a different brand's
        // campaign would attribute this brand's seeded content to that
        // campaign (REQ-M3-008 → mentions.campaign_id), contaminating both
        // brands' results — same coherence rule as the product guard above.
        if ($campaignId !== null
            && Campaign::findOrFail($campaignId)->brand_id !== (int) $validated['seeding_brand_id']) {
            throw ValidationException::withMessages([
                'seeding_campaign_id' => 'The parent campaign must belong to the seeding run\'s brand.',
            ]);
        }

        $previousStatus = $seeding?->status;

        $attributes = [
            'name' => $validated['seeding_name'],
            'seeding_type' => SeedingType::from($validated['seeding_type']),
            'brand_id' => (int) $validated['seeding_brand_id'],
            'product_id' => $productId,
            'campaign_id' => $campaignId,
            'status' => SeedingCampaignStatus::from($validated['seeding_status']),
            // Manual agency input → tier CONFIRMED (glossary ENUM-MetricTier); spec D1.
            'spend' => ($validated['seeding_spend'] ?? '') !== ''
                ? new MetricValue((float) $validated['seeding_spend'], MetricTier::Confirmed, 'spend')
                : null,
        ];

        if ($editing) {
            $seeding->update($attributes);
        } else {
            $seeding = SeedingCampaign::create($attributes);
        }

        $audit->record($editing ? 'seeding_campaign.updated' : 'seeding_campaign.created', $seeding, [
            'name' => $seeding->name,
            'seeding_type' => $seeding->seeding_type->value,
        ]);

        if ($editing && $previousStatus !== $seeding->status) {
            $audit->record('seeding_campaign.status_changed', $seeding, [
                'from' => $previousStatus->value,
                'to' => $seeding->status->value,
            ]);
        }

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', type: 'success', message: $editing ? 'Seeding run updated.' : 'Seeding run created.');
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    // --- delete ------------------------------------------------------------

    public function confirmDelete(int $seedingId): void
    {
        $this->authorize('delete', SeedingCampaign::findOrFail($seedingId));

        $this->confirmingDeleteId = $seedingId;
    }

    public function delete(AuditLogger $audit): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $seeding = SeedingCampaign::findOrFail($this->confirmingDeleteId);

        $this->authorize('delete', $seeding);

        try {
            // Savepoint so a restrict-FK refusal leaves the connection usable.
            DB::transaction(fn () => $seeding->delete());
        } catch (QueryException) {
            $this->confirmingDeleteId = null;
            $this->dispatch('notify', type: 'error', message: 'Cannot delete: this seeding run is still referenced by shipments or documents.');

            return;
        }

        $audit->record('seeding_campaign.deleted', $seeding, ['name' => $seeding->name]);

        $this->confirmingDeleteId = null;
        $this->clearSelection();
        $this->clampPage();
        $this->dispatch('notify', type: 'success', message: 'Seeding run deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    /** After deletes/filter-affecting mutations, leave no out-of-range page. */
    protected function clampPage(): void
    {
        if ($this->getPage() > 1 && $this->seedingQuery()->paginate($this->perPage())->isEmpty()) {
            $this->resetPage();
        }
    }

    // -------------------------------------------------------------------------

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingSeedingId = null;
        $this->seeding_name = '';
        $this->seeding_type = '';
        $this->seeding_brand_id = '';
        $this->seeding_product_id = '';
        $this->seeding_campaign_id = '';
        $this->seeding_status = '';
        $this->seeding_spend = '';
    }

    public function render(): View
    {
        return view('livewire.crm.seeding-campaigns-index', [
            'seedingCampaigns' => $this->seedingQuery()->paginate($this->perPage()),
            'brands' => Brand::orderBy('name')->get(),
            'products' => Product::orderBy('name')->get(),
            // Parent-campaign options follow the selected brand (M1): a run
            // may only hang under a campaign of its own brand — save()
            // re-enforces this server-side.
            'campaigns' => $this->seeding_brand_id !== ''
                ? Campaign::where('brand_id', (int) $this->seeding_brand_id)->orderBy('name')->get()
                : Campaign::query()->whereRaw('false')->get(),
            'types' => SeedingType::cases(),
            'statuses' => SeedingCampaignStatus::cases(),
            'typeDescriptions' => collect(SeedingType::cases())
                ->mapWithKeys(fn ($t) => [$t->value => $t->description()])
                ->all(),
            'statusDescriptions' => collect(SeedingCampaignStatus::cases())
                ->mapWithKeys(fn ($s) => [$s->value => $s->description()])
                ->all(),
        ]);
    }
}
