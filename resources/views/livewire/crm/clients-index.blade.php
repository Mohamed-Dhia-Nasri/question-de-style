<div>
    <x-table.container>
        <x-slot:header>
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative grow sm:max-w-xs">
                    <x-form.input wire:model.live.debounce.300ms="search" type="search"
                        placeholder="Search clients…" aria-label="Search clients" />
                </div>

                <div class="w-full sm:w-28">
                    <x-form.select wire:model.live="perPage" aria-label="Rows per page">
                        <option value="10">10 / page</option>
                        <option value="25">25 / page</option>
                        <option value="50">50 / page</option>
                    </x-form.select>
                </div>

                <div class="grow"></div>

                @can('create', \App\Modules\CRM\Models\Client::class)
                    <x-ui.button wire:click="create">New client</x-ui.button>
                @endcan
            </div>
        </x-slot:header>

        @if ($clients->isEmpty())
            @if ($search !== '')
                <x-states.empty title="No clients match your search">
                    Try a different search term.
                </x-states.empty>
            @else
                <x-states.empty title="No clients yet">
                    A client is the company your agency works for — brands, products, and campaigns
                    all hang off a client.
                    <x-slot:action>
                        @can('create', \App\Modules\CRM\Models\Client::class)
                            <x-ui.button size="sm" wire:click="create">New client</x-ui.button>
                        @endcan
                    </x-slot:action>
                </x-states.empty>
            @endif
        @else
            <table class="w-full min-w-[700px]">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <x-table.th field="name" :sort-field="$sortField" :sort-direction="$sortDirection">Name</x-table.th>
                        <x-table.th field="country" :sort-field="$sortField" :sort-direction="$sortDirection">Country</x-table.th>
                        <x-table.th>Brands</x-table.th>
                        <x-table.th field="created_at" :sort-field="$sortField" :sort-direction="$sortDirection">Created</x-table.th>
                        <x-table.th><span class="sr-only">Actions</span></x-table.th>
                    </tr>
                </thead>
                @foreach ($clients as $client)
                    <tbody x-data="{ open: false }" wire:key="client-{{ $client->id }}"
                        wire:loading.class="pointer-events-none opacity-50"
                        class="divide-y divide-gray-100 transition-opacity dark:divide-gray-800">
                        <tr>
                            <td class="px-5 py-4">
                                <button type="button" x-on:click="open = !open" :aria-expanded="open"
                                    class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-white/90">
                                    <svg :class="open ? 'rotate-90' : ''" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    {{ $client->name }}
                                </button>
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ \App\Shared\Enums\Country::labelFor($client->country) ?? '—' }}</td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $client->brands_count }}</td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $client->created_at->format('d.m.Y') }}</td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    @can('update', $client)
                                        <button type="button" wire:click="edit({{ $client->id }})"
                                            class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Edit</button>
                                    @endcan
                                    @can('delete', $client)
                                        <button type="button" wire:click="confirmDelete({{ $client->id }})"
                                            class="text-sm font-medium text-error-500 hover:text-error-600">Delete</button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                        <tr x-show="open" x-cloak>
                            <td colspan="5" class="bg-gray-50 px-5 py-3 dark:bg-white/[0.02]">
                                @if ($client->brands->isEmpty())
                                    <p class="text-sm text-gray-500 dark:text-gray-400">No brands yet — create one on the <a href="{{ route('crm.brands.index') }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Brands page</a>.</p>
                                @else
                                    <ul class="flex flex-wrap gap-x-6 gap-y-1.5">
                                        @foreach ($client->brands as $brand)
                                            <li>
                                                <a href="{{ route('crm.brands.show', $brand) }}" class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $brand->name }}</a>
                                                @if ($brand->sector)
                                                    <span class="text-theme-xs text-gray-400">· {{ $brand->sector->label() }}</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                @endforeach
            </table>
        @endif

        <x-slot:footer>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Showing {{ $clients->count() }} of {{ $clients->total() }} clients
                </p>
                {{ $clients->links() }}
            </div>
        </x-slot:footer>
    </x-table.container>

    @if ($showForm)
        <x-ui.modal :title="$editingClientId ? 'Edit client' : 'New client'" close-action="cancelForm">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <x-form.label for="client_name" required>Name</x-form.label>
                    <x-form.input id="client_name" wire:model="client_name" :error="$errors->has('client_name')" />
                    <x-form.error for="client_name" />
                </div>

                <div>
                    <x-form.label for="client_country">Country</x-form.label>
                    <x-form.select id="client_country" wire:model="client_country">
                        <option value="">— none —</option>
                        @foreach ($countries as $country)
                            <option value="{{ $country->value }}">{{ $country->label() }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.error for="client_country" />
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelForm" wire:loading.attr="disabled">Cancel</x-ui.button>
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editingClientId ? 'Save changes' : 'Create client' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($confirmingDeleteId !== null)
        <x-ui.confirm-modal title="Delete client?" confirm-action="delete" cancel-action="cancelDelete"
            confirm-label="Delete client">
            Clients with brands cannot be deleted. The action is recorded in the audit log.
        </x-ui.confirm-modal>
    @endif
</div>
