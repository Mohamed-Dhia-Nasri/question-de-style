<?php

namespace App\Modules\CRM\Livewire\Creators;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Services\CreatorWriter;
use App\Modules\Discovery\Models\GeoAttribution;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\Country;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\Livewire\Concerns\WithDataTable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * CRM creators index (spec §2.4) — Module 3's OWN staff-facing list of
 * ENT-Creator records, distinct from the read-only monitoring dashboard
 * list. Follows the UsersIndex reference CRUD pattern (ADR-0012):
 * searchable/sortable/paginated table, modal create, delete confirmation,
 * server-side authorization on every action, audit on sensitive changes.
 *
 * Create and delete are the operator's identity levers under ADR-0014:
 * deleting a stray auto-proposed duplicate IS the v1 merge story. All
 * Creator writes route through CreatorWriter (ownership matrix).
 */
class CreatorsIndex extends Component
{
    use WithDataTable;

    #[Url(except: '')]
    public string $statusFilter = '';

    /** Creator geography filters (ADR-0018) — geography belongs to CREATORS. */
    #[Url(except: '')]
    public string $countryFilter = '';

    #[Url(except: '')]
    public string $cityFilter = '';

    // --- create form state ---
    public bool $showForm = false;

    public string $display_name = '';

    public string $primary_language = '';

    public string $relationship_status = '';

    // --- delete confirmation state ---
    public ?int $confirmingDeleteId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Creator::class);

        if ($this->sortField === '') {
            $this->sortField = 'display_name';
        }

        // Overview quick-action deep link (F02) — the can() guard keeps a
        // crm.view-only visitor on a working page instead of a 403.
        if (request()->boolean('create') && auth()->user()->can('create', Creator::class)) {
            $this->create();
        }
    }

    protected function sortableColumns(): array
    {
        return ['display_name', 'relationship_status', 'created_at'];
    }

    protected function currentPageIds(): array
    {
        return $this->creatorsQuery()
            ->paginate($this->perPage())
            ->pluck('id')
            ->all();
    }

    /** @return Builder<Creator> */
    protected function creatorsQuery(): Builder
    {
        return $this->applySort(
            Creator::query()
                ->with(['platformAccounts', 'geoAttribution'])
                ->when($this->search !== '', function (Builder $query) {
                    $query->where(function (Builder $query) {
                        $query->where('display_name', 'ilike', '%'.$this->search.'%')
                            ->orWhereHas('platformAccounts', function (Builder $query) {
                                $query->where('handle', 'ilike', '%'.$this->search.'%');
                            });
                    });
                })
                ->when($this->statusFilter !== '', function (Builder $query) {
                    // Validated against the closed ENUM-RelationshipStatus set.
                    if (RelationshipStatus::tryFrom($this->statusFilter) !== null) {
                        $query->where('relationship_status', $this->statusFilter);
                    }
                })
                // Geography filters ride the operator-assigned attribution
                // (ADR-0018); values validate against the closed Country set
                // / the cities that actually exist — never raw input.
                ->when(Country::tryFrom(strtoupper(trim($this->countryFilter))) !== null, function (Builder $query) {
                    $query->whereHas('geoAttribution', fn (Builder $geo) => $geo
                        ->where('country_code', strtoupper(trim($this->countryFilter))));
                })
                ->when(trim($this->cityFilter) !== '', function (Builder $query) {
                    $city = trim($this->cityFilter);

                    if (GeoAttribution::query()->where('city', $city)->exists()) {
                        $query->whereHas('geoAttribution', fn (Builder $geo) => $geo->where('city', $city));
                    }
                })
        );
    }

    public function updatingCountryFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatingCityFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    // --- create -------------------------------------------------------------

    public function create(): void
    {
        $this->authorize('create', Creator::class);

        $this->resetForm();
        $this->showForm = true;
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'display_name' => 'display name',
            'primary_language' => 'primary language',
            'relationship_status' => 'relationship status',
        ];
    }

    public function save(CreatorWriter $writer, AuditLogger $audit): void
    {
        $this->authorize('create', Creator::class);

        $validated = $this->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'primary_language' => ['nullable', 'string', 'max:10'],
            'relationship_status' => ['nullable', Rule::in(array_column(RelationshipStatus::cases(), 'value'))],
        ]);

        $creator = $writer->createCreator(
            $validated['display_name'],
            ($validated['primary_language'] ?? '') !== '' ? $validated['primary_language'] : null,
            ($validated['relationship_status'] ?? '') !== ''
                ? RelationshipStatus::from($validated['relationship_status'])
                : null,
        );

        // Identifier-only context — the subject_id already identifies the row;
        // the display name is PII and must not persist in append-only audit (M29).
        $audit->record('creator.created', $creator);

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', type: 'success', message: 'Creator created.');
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    /**
     * After the CSV import writes new creators, re-render so the freshly
     * imported rows appear without a manual reload. The import itself is done
     * by the sibling CreatorCsvImport component; this is only a refresh hook.
     */
    #[On('creators-imported')]
    public function refreshAfterImport(): void {}

    // --- delete (the ADR-0014 stray-duplicate lever) -------------------------

    public function confirmDelete(int $creatorId): void
    {
        $this->authorize('delete', Creator::findOrFail($creatorId));

        $this->confirmingDeleteId = $creatorId;
    }

    public function delete(CreatorWriter $writer, AuditLogger $audit): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $creator = Creator::findOrFail($this->confirmingDeleteId);

        $this->authorize('delete', $creator);

        try {
            $writer->deleteCreator($creator);
        } catch (QueryException) {
            // Restrict FKs guard other modules' data (M1 monitoring history —
            // content, snapshots, mentions) and M3 records not managed on the
            // profile (shipments, documents, tasks). The lifecycle-coupled
            // roster entry alone never blocks (it is withdrawn with the
            // creator); history does. Never force those deletes.
            $this->confirmingDeleteId = null;
            $this->dispatch('notify', type: 'error', message: 'Cannot delete: this creator still has monitoring history or campaign records.');

            return;
        }

        $audit->record('creator.deleted', $creator);

        $this->confirmingDeleteId = null;
        $this->clearSelection();
        $this->clampPage();
        $this->dispatch('notify', type: 'success', message: 'Creator deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    /** After deletes/filter-affecting mutations, leave no out-of-range page. */
    protected function clampPage(): void
    {
        if ($this->getPage() > 1 && $this->creatorsQuery()->paginate($this->perPage())->isEmpty()) {
            $this->resetPage();
        }
    }

    // -------------------------------------------------------------------------

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->display_name = '';
        $this->primary_language = '';
        $this->relationship_status = '';
    }

    public function render(): View
    {
        return view('livewire.crm.creators-index', [
            'creators' => $this->creatorsQuery()->paginate($this->perPage()),
            'statuses' => RelationshipStatus::cases(),
            'statusDescriptions' => collect(RelationshipStatus::cases())
                ->mapWithKeys(fn ($s) => [$s->value => $s->description()])
                ->all(),
            'countries' => Country::cases(),
            // City options mirror what operators actually assigned (ADR-0018).
            'cities' => GeoAttribution::query()
                ->whereNotNull('city')->distinct()->orderBy('city')->pluck('city'),
        ]);
    }
}
