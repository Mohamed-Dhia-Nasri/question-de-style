<?php

namespace App\Modules\CRM\Livewire\Clients;

use App\Modules\CRM\Models\Client;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\Country;
use App\Shared\Livewire\Concerns\WithDataTable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Clients index (REQ-M3-005) — top of the client → brand → product master
 * hierarchy. UsersIndex reference CRUD pattern (ADR-0012): searchable/
 * sortable/paginated, modal create/edit, delete confirmation, server-side
 * authorization on every action, audit on sensitive changes.
 */
class ClientsIndex extends Component
{
    use WithDataTable;

    // --- create/edit form state ---
    public bool $showForm = false;

    public ?int $editingClientId = null;

    public string $client_name = '';

    public string $client_country = '';

    // --- delete confirmation state ---
    public ?int $confirmingDeleteId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Client::class);

        if ($this->sortField === '') {
            $this->sortField = 'name';
        }
    }

    protected function sortableColumns(): array
    {
        return ['name', 'country', 'created_at'];
    }

    protected function currentPageIds(): array
    {
        return $this->clientsQuery()->paginate($this->perPage())->pluck('id')->all();
    }

    /** @return Builder<Client> */
    protected function clientsQuery(): Builder
    {
        return $this->applySort(
            Client::query()
                ->withCount('brands')
                ->when($this->search !== '', function (Builder $query) {
                    $query->where('name', 'ilike', '%'.$this->search.'%');
                })
        );
    }

    // --- create / edit -----------------------------------------------------

    public function create(): void
    {
        $this->authorize('create', Client::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $clientId): void
    {
        $client = Client::findOrFail($clientId);

        $this->authorize('update', $client);

        $this->resetForm();
        $this->editingClientId = $client->id;
        $this->client_name = $client->name;
        $this->client_country = $client->country ?? '';
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $editing = $this->editingClientId !== null;
        $client = $editing ? Client::findOrFail($this->editingClientId) : null;

        $this->authorize($editing ? 'update' : 'create', $client ?? Client::class);

        // Case-insensitive on entry (the select sends uppercase; direct
        // input normalizes before the closed-set check) — GeographyPanel
        // precedent.
        $this->client_country = strtoupper(trim($this->client_country));

        $validated = $this->validate([
            'client_name' => ['required', 'string', 'max:255'],
            // ENT-Client.country — stored as a 2-char code; the picker
            // offers the operating countries only (DACH + France).
            'client_country' => ['nullable', 'string', Rule::in(Country::values())],
        ]);

        $attributes = [
            'name' => $validated['client_name'],
            'country' => ($validated['client_country'] ?? '') !== '' ? strtoupper($validated['client_country']) : null,
        ];

        if ($editing) {
            $client->update($attributes);
        } else {
            $client = Client::create($attributes);
        }

        $audit->record($editing ? 'client.updated' : 'client.created', $client, ['name' => $client->name]);

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', type: 'success', message: $editing ? 'Client updated.' : 'Client created.');
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    // --- delete ------------------------------------------------------------

    public function confirmDelete(int $clientId): void
    {
        $this->authorize('delete', Client::findOrFail($clientId));

        $this->confirmingDeleteId = $clientId;
    }

    public function delete(AuditLogger $audit): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $client = Client::findOrFail($this->confirmingDeleteId);

        $this->authorize('delete', $client);

        try {
            // Savepoint so a restrict-FK refusal leaves the connection usable.
            DB::transaction(fn () => $client->delete());
        } catch (QueryException) {
            $this->confirmingDeleteId = null;
            $this->dispatch('notify', type: 'error', message: 'Cannot delete: this client still has brands.');

            return;
        }

        $audit->record('client.deleted', $client, ['name' => $client->name]);

        $this->confirmingDeleteId = null;
        $this->clearSelection();
        $this->clampPage();
        $this->dispatch('notify', type: 'success', message: 'Client deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    /** After deletes/filter-affecting mutations, leave no out-of-range page. */
    protected function clampPage(): void
    {
        if ($this->getPage() > 1 && $this->clientsQuery()->paginate($this->perPage())->isEmpty()) {
            $this->resetPage();
        }
    }

    // -------------------------------------------------------------------------

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingClientId = null;
        $this->client_name = '';
        $this->client_country = '';
    }

    public function render(): View
    {
        return view('livewire.crm.clients-index', [
            'clients' => $this->clientsQuery()->paginate($this->perPage()),
            'countries' => Country::cases(),
        ]);
    }
}
