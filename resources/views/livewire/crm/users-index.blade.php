<div>
    <x-table.container>
        {{-- Toolbar --}}
        <x-slot:header>
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="relative grow sm:max-w-xs">
                        <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-gray-500 dark:text-gray-400">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd" clip-rule="evenodd" d="M3.04175 9.37363C3.04175 5.87693 5.87711 3.04199 9.37508 3.04199C12.8731 3.04199 15.7084 5.87693 15.7084 9.37363C15.7084 12.8703 12.8731 15.7053 9.37508 15.7053C5.87711 15.7053 3.04175 12.8703 3.04175 9.37363ZM9.37508 1.54199C5.04902 1.54199 1.54175 5.04817 1.54175 9.37363C1.54175 13.6991 5.04902 17.2053 9.37508 17.2053C11.2674 17.2053 13.003 16.5344 14.357 15.4176L17.177 18.238C17.4699 18.5309 17.9448 18.5309 18.2377 18.238C18.5306 17.9451 18.5306 17.4703 18.2377 17.1774L15.418 14.3573C16.5365 13.0033 17.2084 11.2669 17.2084 9.37363C17.2084 5.04817 13.7011 1.54199 9.37508 1.54199Z" fill="currentColor" />
                            </svg>
                        </span>
                        <x-form.input wire:model.live.debounce.300ms="search" type="search" class="pl-11"
                            placeholder="Search name or email…" aria-label="Search users" />
                    </div>

                    <div class="w-full sm:w-48">
                        <x-form.select wire:model.live="roleFilter" aria-label="Filter by role">
                            <option value="">All roles</option>
                            @foreach ($roles as $roleOption)
                                <option value="{{ $roleOption->value }}">{{ $roleOption->label() }}</option>
                            @endforeach
                        </x-form.select>
                    </div>

                    <div class="w-full sm:w-40">
                        <x-form.select wire:model.live="statusFilter" aria-label="Filter by status">
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </x-form.select>
                    </div>

                    <div class="w-full sm:w-28">
                        <x-form.select wire:model.live="perPage" aria-label="Rows per page">
                            <option value="10">10 / page</option>
                            <option value="25">25 / page</option>
                            <option value="50">50 / page</option>
                        </x-form.select>
                    </div>

                    <div class="grow"></div>

                    <x-ui.button wire:click="create">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 5v14m-7-7h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                        New user
                    </x-ui.button>
                </div>

                {{-- Bulk-action bar --}}
                @if (count($selected) > 0)
                    <div class="flex flex-wrap items-center gap-3 rounded-lg bg-brand-50 px-4 py-2.5 dark:bg-brand-500/10">
                        <span class="text-sm font-medium text-brand-600 dark:text-brand-400">
                            {{ count($selected) }} selected
                        </span>
                        <x-ui.button variant="outline" size="sm" wire:click="bulkSetActive(true)"
                            wire:loading.attr="disabled">
                            Activate
                        </x-ui.button>
                        <x-ui.button variant="outline" size="sm" wire:click="bulkSetActive(false)"
                            wire:loading.attr="disabled">
                            Deactivate
                        </x-ui.button>
                    </div>
                @endif
            </div>
        </x-slot:header>

        @if ($users->isEmpty())
            @if ($search !== '' || $roleFilter !== '' || $statusFilter !== '')
                <x-states.empty title="No users match your filters">
                    Try adjusting or clearing the search and filters above.
                </x-states.empty>
            @else
                <x-states.empty title="No team members yet">
                    Invite the people who will work in QDS.
                    <x-slot:action>
                        <x-ui.button size="sm" wire:click="create">New user</x-ui.button>
                    </x-slot:action>
                </x-states.empty>
            @endif
        @else
            <table class="w-full min-w-[900px]">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <th class="w-12 px-5 py-3">
                            <input type="checkbox" wire:model.live="selectPage" aria-label="Select page"
                                class="h-5 w-5 cursor-pointer rounded-md border-gray-300 text-brand-500 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900" />
                        </th>
                        <x-table.th field="display_name" :sort-field="$sortField" :sort-direction="$sortDirection">Name</x-table.th>
                        <x-table.th field="email" :sort-field="$sortField" :sort-direction="$sortDirection">Email</x-table.th>
                        <x-table.th>Role</x-table.th>
                        <x-table.th field="active" :sort-field="$sortField" :sort-direction="$sortDirection">Status</x-table.th>
                        <x-table.th field="created_at" :sort-field="$sortField" :sort-direction="$sortDirection">Created</x-table.th>
                        <x-table.th><span class="sr-only">Actions</span></x-table.th>
                    </tr>
                </thead>
                <tbody wire:loading.class="pointer-events-none opacity-50"
                    class="divide-y divide-gray-100 transition-opacity dark:divide-gray-800">
                    @foreach ($users as $user)
                        <tr wire:key="user-{{ $user->id }}">
                            <td class="px-5 py-4">
                                <input type="checkbox" wire:model.live="selected" value="{{ $user->id }}"
                                    aria-label="Select {{ $user->display_name }}"
                                    class="h-5 w-5 cursor-pointer rounded-md border-gray-300 text-brand-500 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900" />
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-600 dark:bg-brand-500/[0.12] dark:text-brand-400">
                                        {{ $user->initials() }}
                                    </span>
                                    <span class="text-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ $user->display_name }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $user->email }}</td>
                            <td class="px-5 py-4">
                                @if ($user->roleName())
                                    <x-ui.badge color="light">{{ $user->roleName()->label() }}</x-ui.badge>
                                @else
                                    <span class="text-sm text-gray-400">&mdash;</span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                @if ($user->active)
                                    <x-ui.badge color="success">Active</x-ui.badge>
                                @else
                                    <x-ui.badge color="light">Inactive</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ $user->created_at->format('d.m.Y') }}
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    <button type="button" wire:click="edit({{ $user->id }})"
                                        class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                        Edit
                                    </button>
                                    @if (auth()->id() !== $user->id && (int) $user->id !== (int) $ownerUserId)
                                        <button type="button" wire:click="confirmDelete({{ $user->id }})"
                                            class="text-sm font-medium text-error-500 hover:text-error-600">
                                            Delete
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <x-slot:footer>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Showing {{ $users->count() }} of {{ $users->total() }} users
                </p>
                {{ $users->links() }}
            </div>
        </x-slot:footer>
    </x-table.container>

    {{-- Create / edit modal --}}
    @if ($showForm)
        <x-ui.modal :title="$editingUserId ? 'Edit user' : 'New user'" close-action="cancelForm">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <x-form.label for="display_name" required>Name</x-form.label>
                    <x-form.input id="display_name" wire:model="display_name"
                        :error="$errors->has('display_name')" placeholder="Full name" />
                    <x-form.error for="display_name" />
                </div>

                <div>
                    <x-form.label for="email" required>Email</x-form.label>
                    <x-form.input id="email" wire:model="email" type="email"
                        :error="$errors->has('email')" placeholder="name@agency.de" />
                    <x-form.error for="email" />
                </div>

                <div>
                    <x-form.label for="role" required>Role</x-form.label>
                    <x-form.select id="role" wire:model="role" :error="$errors->has('role')">
                        <option value="">Select a role…</option>
                        @foreach ($roles as $roleOption)
                            <option value="{{ $roleOption->value }}">{{ $roleOption->label() }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.error for="role" />
                </div>

                <div>
                    <x-form.label for="password" :required="$editingUserId === null">Password</x-form.label>
                    <x-form.input id="password" wire:model="password" type="password"
                        :error="$errors->has('password')" autocomplete="new-password" />
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                        @if ($editingUserId)
                            Leave blank to keep the current password. Minimum 12 characters.
                        @else
                            Minimum 12 characters.
                        @endif
                    </p>
                    <x-form.error for="password" />
                </div>

                <x-form.toggle wire:model="active" label="Active — user may sign in" />
                {{-- Seat-limit violations (ADR-0021): active users consume seats. --}}
                <x-form.error for="seats" />
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelForm" wire:loading.attr="disabled">
                    Cancel
                </x-ui.button>
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editingUserId ? 'Save changes' : 'Create user' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Delete confirmation --}}
    @if ($confirmingDeleteId !== null)
        <x-ui.confirm-modal title="Delete user?" confirm-action="delete" cancel-action="cancelDelete"
            confirm-label="Delete user">
            This permanently removes the user's account and access. The action is recorded in the
            audit log. This cannot be undone.
        </x-ui.confirm-modal>
    @endif
</div>
