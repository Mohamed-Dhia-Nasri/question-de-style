<?php

namespace App\Modules\CRM\Livewire\Users;

use App\Models\User;
use App\Modules\Billing\Exceptions\SeatLimitExceeded;
use App\Modules\Billing\Services\SeatLimiter;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\RoleName;
use App\Shared\Livewire\Concerns\WithDataTable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * User administration — the REFERENCE Livewire CRUD implementation whose
 * patterns future Module 3 screens follow (ADR-0012, roadmap P3 note):
 * searchable/sortable/filterable/paginated table, bulk selection, modal
 * create/edit form with server-side validation, delete confirmation,
 * authorization on every action, and audit events for sensitive changes.
 *
 * ENT-User writes are ADMIN-only (ownership matrix, REQ-M3-012): the route is
 * gated on users.manage, every Livewire action re-authorizes server-side via
 * UserPolicy, and each user holds exactly one ENUM-RoleName role.
 */
class UsersIndex extends Component
{
    use WithDataTable;

    #[Url(except: '')]
    public string $roleFilter = '';

    /** '', 'active' or 'inactive' */
    #[Url(except: '')]
    public string $statusFilter = '';

    // --- create/edit form state ---
    public bool $showForm = false;

    public ?int $editingUserId = null;

    public string $display_name = '';

    public string $email = '';

    public string $role = '';

    public bool $active = true;

    public string $password = '';

    // --- delete confirmation state ---
    public ?int $confirmingDeleteId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);

        // Default only when the query string didn't deep-link a sort
        // (#[Url] hydration runs before mount).
        if ($this->sortField === '') {
            $this->sortField = 'display_name';
        }
    }

    protected function sortableColumns(): array
    {
        return ['display_name', 'email', 'active', 'created_at'];
    }

    protected function currentPageIds(): array
    {
        return $this->usersQuery()
            ->paginate($this->perPage())
            ->pluck('id')
            ->all();
    }

    /** @return Builder<User> */
    protected function usersQuery(): Builder
    {
        return $this->applySort(
            User::query()
                ->with('roles')
                ->when($this->search !== '', function (Builder $query) {
                    $query->where(function (Builder $query) {
                        $query->where('display_name', 'ilike', '%'.$this->search.'%')
                            ->orWhere('email', 'ilike', '%'.$this->search.'%');
                    });
                })
                ->when($this->roleFilter !== '', function (Builder $query) {
                    // Validated against the closed ENUM-RoleName set.
                    if (RoleName::tryFrom($this->roleFilter) !== null) {
                        $query->role($this->roleFilter);
                    }
                })
                ->when($this->statusFilter === 'active', fn (Builder $query) => $query->where('active', true))
                ->when($this->statusFilter === 'inactive', fn (Builder $query) => $query->where('active', false))
        );
    }

    public function updatingRoleFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    // --- create / edit -----------------------------------------------------

    public function create(): void
    {
        $this->authorize('create', User::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $userId): void
    {
        $user = User::findOrFail($userId);

        $this->authorize('update', $user);

        $this->resetForm();
        $this->editingUserId = $user->id;
        $this->display_name = $user->display_name;
        $this->email = $user->email;
        $this->role = $user->roleName()->value ?? '';
        $this->active = $user->active;
        $this->showForm = true;
    }

    public function save(AuditLogger $audit, SeatLimiter $seats): void
    {
        $editing = $this->editingUserId !== null;
        $user = $editing ? User::findOrFail($this->editingUserId) : null;

        $this->authorize($editing ? 'update' : 'create', $user ?? User::class);

        $validated = $this->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingUserId)],
            'role' => ['required', Rule::in(array_column($this->allowedRoles(), 'value'))],
            'active' => ['boolean'],
            'password' => $editing ? ['nullable', 'string', 'min:12'] : ['required', 'string', 'min:12'],
        ]);

        // Self-lockout guard: admins may not demote or deactivate their own
        // account (the only path to a zero-ADMIN tenant — every other admin
        // edit still leaves the actor as an ADMIN). Mirrors the self-checks
        // in bulkSetActive() and UserPolicy::delete().
        if ($editing && $user->is(auth()->user())) {
            if ($validated['role'] !== $user->roleName()?->value) {
                throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
            }

            if (! $validated['active']) {
                throw ValidationException::withMessages(['active' => 'You cannot deactivate your own account.']);
            }
        }

        // Seat model (ADR-0021): creating an active user or reactivating an
        // inactive one consumes a seat — those paths run under the tenant
        // seat lock so a concurrent change cannot overshoot the limit.
        $consumesSeat = $editing
            ? ((bool) $validated['active'] && ! $user->active)
            : (bool) $validated['active'];

        try {
            $user = $consumesSeat
                ? $seats->reserve((int) auth()->user()->tenant_id, fn (): User => $this->persistUser($editing, $user, $validated))
                : $this->persistUser($editing, $user, $validated);
        } catch (SeatLimitExceeded $e) {
            throw ValidationException::withMessages(['seats' => $e->getMessage()]);
        }

        $audit->record($editing ? 'user.updated' : 'user.created', $user, ['role' => $validated['role'], 'active' => $user->active]);

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', type: 'success', message: $editing ? 'User updated.' : 'User created.');
    }

    /** @param  array<string, mixed>  $validated */
    protected function persistUser(bool $editing, ?User $user, array $validated): User
    {
        if ($editing) {
            $user->fill([
                'display_name' => $validated['display_name'],
                'email' => $validated['email'],
                'active' => $validated['active'],
            ]);

            if ($this->password !== '') {
                $user->password = $validated['password'];
            }

            $user->save();
        } else {
            $user = User::create([
                'display_name' => $validated['display_name'],
                'email' => $validated['email'],
                'active' => $validated['active'],
                'password' => $validated['password'],
            ]);
        }

        // Exactly one role per user (ENT-User): syncRoles, never assignRole.
        $user->syncRoles([$validated['role']]);

        return $user;
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    // --- delete ------------------------------------------------------------

    public function confirmDelete(int $userId): void
    {
        $this->authorize('delete', User::findOrFail($userId));

        $this->confirmingDeleteId = $userId;
    }

    public function delete(AuditLogger $audit): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $user = User::findOrFail($this->confirmingDeleteId);

        $this->authorize('delete', $user);

        $audit->record('user.deleted', $user);

        $user->delete();

        $this->confirmingDeleteId = null;
        $this->clearSelection();
        $this->clampPage();
        $this->dispatch('notify', type: 'success', message: 'User deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    // --- bulk actions --------------------------------------------------------

    public function bulkSetActive(bool $active, AuditLogger $audit, SeatLimiter $seats): void
    {
        $this->authorize('viewAny', User::class);

        $apply = function () use ($active, $audit): int {
            $users = User::query()->whereIn('id', $this->selected)->get();

            $count = 0;

            foreach ($users as $user) {
                // Skip self-deactivation and re-check per record.
                if (! $active && $user->is(auth()->user())) {
                    continue;
                }

                $this->authorize('update', $user);

                if ($user->active !== $active) {
                    $user->update(['active' => $active]);
                    $audit->record($active ? 'user.activated' : 'user.deactivated', $user);
                    $count++;
                }
            }

            return $count;
        };

        try {
            // Bulk ACTIVATION consumes seats — all-or-nothing under the
            // tenant seat lock (ADR-0021); deactivation only frees seats.
            $count = $active
                ? $seats->reserve((int) auth()->user()->tenant_id, $apply)
                : $apply();
        } catch (SeatLimitExceeded $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        $this->clearSelection();
        $this->clampPage();
        $this->dispatch('notify', type: 'success', message: "{$count} user(s) ".($active ? 'activated.' : 'deactivated.'));
    }

    /** After deletes/filter-affecting mutations, leave no out-of-range page. */
    protected function clampPage(): void
    {
        if ($this->getPage() > 1 && $this->usersQuery()->paginate($this->perPage())->isEmpty()) {
            $this->resetPage();
        }
    }

    // -------------------------------------------------------------------------

    /**
     * ADR-0016: no client accounts are created on any path — staff roles
     * only, matching the invitation panel (TeamInvitationsPanel::invite). An
     * existing CLIENT_VIEWER user stays editable (their current role remains
     * valid for them), but nobody new can be pointed at the empty shell.
     *
     * @return list<RoleName>
     */
    protected function allowedRoles(): array
    {
        $roles = RoleName::staff();

        if ($this->editingUserId !== null) {
            $current = User::query()->findOrFail($this->editingUserId)->roleName();

            if ($current !== null && ! in_array($current, $roles, true)) {
                $roles[] = $current;
            }
        }

        return $roles;
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingUserId = null;
        $this->display_name = '';
        $this->email = '';
        $this->role = '';
        $this->active = true;
        $this->password = '';
    }

    public function render(): View
    {
        return view('livewire.crm.users-index', [
            'users' => $this->usersQuery()->paginate($this->perPage()),
            'roles' => $this->allowedRoles(),
            // The tenant's billing owner cannot be deleted (RESTRICT FK) —
            // resolved once here so the row template needs no per-row query.
            'ownerUserId' => auth()->user()->tenant()->value('owner_user_id'),
        ]);
    }
}
