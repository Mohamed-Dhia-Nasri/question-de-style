<?php

namespace App\Modules\CRM\Livewire\Campaigns;

use App\Modules\CRM\Exceptions\CampaignBrandLocked;
use App\Modules\CRM\Exceptions\CampaignStatusTransitionNotAllowed;
use App\Modules\CRM\Livewire\Concerns\WithInlineCreate;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Client;
use App\Modules\CRM\Services\CampaignWriter;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\MetricTier;
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
 * Campaigns index (REQ-M3-005) — ENT-Campaign CRUD on the UsersIndex
 * reference pattern (ADR-0012). AC-M3-009: the status is always exactly one
 * ENUM-CampaignStatus value (closed-set validated) and every status
 * transition is recorded (campaign.status_changed, from → to). `spend` is
 * the agency-entered CONFIRMED MetricValue feeding CPE/CPM (AC-M3-015,
 * spec §2.1/D1); its changes ride the campaign.updated audit event.
 */
class CampaignsIndex extends Component
{
    use WithDataTable;
    use WithInlineCreate;

    #[Url(except: '')]
    public string $statusFilter = '';

    // --- create/edit form state ---
    public bool $showForm = false;

    public ?int $editingCampaignId = null;

    public string $campaign_name = '';

    public string $campaign_brand_id = '';

    public string $campaign_status = '';

    public string $campaign_start_at = '';

    public string $campaign_end_at = '';

    public string $campaign_spend = '';

    public string $campaign_objective = '';

    /** One market per line (ENT-Campaign.markets — list of string). */
    public string $campaign_markets = '';

    // --- delete confirmation state ---
    public ?int $confirmingDeleteId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Campaign::class);

        if ($this->sortField === '') {
            $this->sortField = 'name';
        }
    }

    protected function sortableColumns(): array
    {
        return ['name', 'status', 'start_at', 'created_at'];
    }

    protected function currentPageIds(): array
    {
        return $this->campaignsQuery()->paginate($this->perPage())->pluck('id')->all();
    }

    /** @return Builder<Campaign> */
    protected function campaignsQuery(): Builder
    {
        return $this->applySort(
            Campaign::query()
                ->with('brand')
                ->withCount('creators')
                ->when($this->search !== '', function (Builder $query) {
                    $query->where('name', 'ilike', '%'.$this->search.'%');
                })
                ->when($this->statusFilter !== '', function (Builder $query) {
                    if (CampaignStatus::tryFrom($this->statusFilter) !== null) {
                        $query->where('status', $this->statusFilter);
                    }
                })
        );
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    // --- create / edit -----------------------------------------------------

    public function create(): void
    {
        $this->authorize('create', Campaign::class);

        $this->resetForm();
        $this->campaign_status = CampaignStatus::Draft->value;
        $this->showForm = true;
    }

    public function edit(int $campaignId): void
    {
        $campaign = Campaign::findOrFail($campaignId);

        $this->authorize('update', $campaign);

        $this->resetForm();
        $this->editingCampaignId = $campaign->id;
        $this->campaign_name = $campaign->name;
        $this->campaign_brand_id = (string) $campaign->brand_id;
        $this->campaign_status = $campaign->status->value;
        $this->campaign_start_at = $campaign->start_at?->format('Y-m-d\TH:i') ?? '';
        $this->campaign_end_at = $campaign->end_at?->format('Y-m-d\TH:i') ?? '';
        $this->campaign_spend = $campaign->spend !== null ? (string) $campaign->spend->amount : '';
        $this->campaign_objective = $campaign->objective ?? '';
        $this->campaign_markets = implode("\n", $campaign->markets ?? []);
        $this->showForm = true;
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return array_merge([
            'campaign_name' => 'name',
            'campaign_brand_id' => 'brand',
            'campaign_status' => 'status',
            'campaign_start_at' => 'start date',
            'campaign_end_at' => 'end date',
            'campaign_spend' => 'spend',
            'campaign_objective' => 'objective',
            'campaign_markets' => 'markets',
        ], $this->inlineValidationAttributes());
    }

    /** @return list<string> */
    protected function inlineCreateTypes(): array
    {
        return ['brand'];
    }

    protected function inlineCreated(string $type, int $id): void
    {
        $this->campaign_brand_id = (string) $id;
    }

    public function save(AuditLogger $audit, CampaignWriter $writer): void
    {
        $editing = $this->editingCampaignId !== null;
        $campaign = $editing ? Campaign::findOrFail($this->editingCampaignId) : null;

        $this->authorize($editing ? 'update' : 'create', $campaign ?? Campaign::class);

        $creating = ! $editing;

        $rules = [
            'campaign_name' => ['required', 'string', 'max:255'],
            'campaign_brand_id' => ['required', 'integer', TenantRule::exists('brands', 'id')],
            'campaign_start_at' => ['nullable', 'date'],
            // Dates are optional and independent; only order them when a start
            // is actually present (else after_or_equal compares against "now"
            // and wrongly rejects an end-only date). M06.
            'campaign_end_at' => ['nullable', 'date', Rule::when($this->campaign_start_at !== '', ['after_or_equal:campaign_start_at'])],
        ];

        if (! $creating) {
            $rules['campaign_status'] = ['required', Rule::in(array_column(CampaignStatus::cases(), 'value'))];
            $rules['campaign_spend'] = ['nullable', 'numeric', 'min:0', 'max:999999999999'];
            $rules['campaign_objective'] = ['nullable', 'string', 'max:2000'];
            $rules['campaign_markets'] = ['nullable', 'string', 'max:2000'];
        }

        $validated = $this->validate($rules);

        if ($creating) {
            // Never read the client-tamperable props on create.
            $validated['campaign_status'] = CampaignStatus::Draft->value;
            $validated['campaign_spend'] = '';
        }

        $attributes = [
            'name' => $validated['campaign_name'],
            'brand_id' => (int) $validated['campaign_brand_id'],
            'status' => CampaignStatus::from($validated['campaign_status']),
            'start_at' => ($validated['campaign_start_at'] ?? '') !== '' ? $validated['campaign_start_at'] : null,
            'end_at' => ($validated['campaign_end_at'] ?? '') !== '' ? $validated['campaign_end_at'] : null,
            // Manual agency input → tier CONFIRMED (glossary ENUM-MetricTier); spec D1.
            'spend' => ($validated['campaign_spend'] ?? '') !== ''
                ? new MetricValue((float) $validated['campaign_spend'], MetricTier::Confirmed, 'spend')
                : null,
        ];

        // ENT-Campaign writes route through the single sanctioned service
        // (CampaignWriter): it houses the brand-coherence guard (F14) and the
        // campaign.updated / campaign.status_changed audit events.
        if ($editing) {
            $objective = trim($validated['campaign_objective'] ?? '');
            $markets = $this->parseMarkets($validated['campaign_markets'] ?? '');
            $attributes['objective'] = $objective !== '' ? $objective : null;
            $attributes['markets'] = $markets !== [] ? $markets : null;

            try {
                $writer->updateCampaign($campaign, $attributes, $audit);
            } catch (CampaignBrandLocked $e) {
                // Block-and-tell: surface the coherence refusal on the brand
                // field instead of silently rewriting the runs' brand_id.
                throw ValidationException::withMessages(['campaign_brand_id' => $e->getMessage()]);
            } catch (CampaignStatusTransitionNotAllowed $e) {
                // Block-and-tell: an illegal lifecycle move surfaces on the
                // status field (M04).
                throw ValidationException::withMessages(['campaign_status' => $e->getMessage()]);
            }
        } else {
            $campaign = $writer->createCampaign($attributes, $audit);
        }

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', type: 'success', message: $editing ? 'Campaign updated.' : 'Campaign created.');
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

    public function confirmDelete(int $campaignId): void
    {
        $this->authorize('delete', Campaign::findOrFail($campaignId));

        $this->confirmingDeleteId = $campaignId;
    }

    public function delete(AuditLogger $audit): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $campaign = Campaign::findOrFail($this->confirmingDeleteId);

        $this->authorize('delete', $campaign);

        try {
            // Savepoint so a restrict-FK refusal leaves the connection usable.
            DB::transaction(fn () => $campaign->delete());
        } catch (QueryException) {
            $this->confirmingDeleteId = null;
            $this->dispatch('notify', type: 'error', message: 'Cannot delete: this campaign is still referenced by seeding runs, mentions, or other records.');

            return;
        }

        $audit->record('campaign.deleted', $campaign, ['name' => $campaign->name]);

        $this->confirmingDeleteId = null;
        $this->clearSelection();
        $this->clampPage();
        $this->dispatch('notify', type: 'success', message: 'Campaign deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    /** After deletes/filter-affecting mutations, leave no out-of-range page. */
    protected function clampPage(): void
    {
        if ($this->getPage() > 1 && $this->campaignsQuery()->paginate($this->perPage())->isEmpty()) {
            $this->resetPage();
        }
    }

    // -------------------------------------------------------------------------

    /**
     * One market per line, blanks dropped — mirrors BrandsIndex::parseLines.
     *
     * @return list<string>
     */
    private function parseMarkets(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingCampaignId = null;
        $this->campaign_name = '';
        $this->campaign_brand_id = '';
        $this->campaign_status = '';
        $this->campaign_start_at = '';
        $this->campaign_end_at = '';
        $this->campaign_spend = '';
        $this->campaign_objective = '';
        $this->campaign_markets = '';
    }

    public function render(): View
    {
        return view('livewire.crm.campaigns-index', [
            'campaigns' => $this->campaignsQuery()->paginate($this->perPage()),
            'brands' => Brand::orderBy('name')->get(),
            'clients' => Client::orderBy('name')->get(),
            'statuses' => CampaignStatus::cases(),
            'statusDescriptions' => collect(CampaignStatus::cases())
                ->mapWithKeys(fn ($s) => [$s->value => $s->description()])
                ->all(),
        ]);
    }
}
