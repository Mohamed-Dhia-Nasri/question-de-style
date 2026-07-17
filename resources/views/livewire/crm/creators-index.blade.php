<div>
    <x-table.container>
        {{-- Toolbar --}}
        <x-slot:header>
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative grow sm:max-w-xs">
                    <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-gray-500 dark:text-gray-400">
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M3.04175 9.37363C3.04175 5.87693 5.87711 3.04199 9.37508 3.04199C12.8731 3.04199 15.7084 5.87693 15.7084 9.37363C15.7084 12.8703 12.8731 15.7053 9.37508 15.7053C5.87711 15.7053 3.04175 12.8703 3.04175 9.37363ZM9.37508 1.54199C5.04902 1.54199 1.54175 5.04817 1.54175 9.37363C1.54175 13.6991 5.04902 17.2053 9.37508 17.2053C11.2674 17.2053 13.003 16.5344 14.357 15.4176L17.177 18.238C17.4699 18.5309 17.9448 18.5309 18.2377 18.238C18.5306 17.9451 18.5306 17.4703 18.2377 17.1774L15.418 14.3573C16.5365 13.0033 17.2084 11.2669 17.2084 9.37363C17.2084 5.04817 13.7011 1.54199 9.37508 1.54199Z" fill="currentColor" />
                        </svg>
                    </span>
                    <x-form.input wire:model.live.debounce.300ms="search" type="search" class="pl-11"
                        placeholder="Search name or handle…" aria-label="Search creators" />
                </div>

                <div class="w-full sm:w-52">
                    <x-form.select wire:model.live="statusFilter" aria-label="Filter by relationship status">
                        <option value="">All relationship statuses</option>
                        @foreach ($statuses as $statusOption)
                            <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                        @endforeach
                    </x-form.select>
                </div>

                <div class="w-full sm:w-44">
                    <x-form.select wire:model.live="countryFilter" aria-label="Filter by creator country">
                        <option value="">All countries</option>
                        @foreach ($countries as $countryOption)
                            <option value="{{ $countryOption->value }}">{{ $countryOption->label() }}</option>
                        @endforeach
                    </x-form.select>
                </div>

                <div class="w-full sm:w-44">
                    <x-form.select wire:model.live="cityFilter" aria-label="Filter by creator city">
                        <option value="">All cities</option>
                        @foreach ($cities as $cityOption)
                            <option value="{{ $cityOption }}">{{ $cityOption }}</option>
                        @endforeach
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

                @can('create', \App\Modules\CRM\Models\Creator::class)
                    <x-ui.button wire:click="create">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 5v14m-7-7h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                        New creator
                    </x-ui.button>
                @endcan
            </div>
        </x-slot:header>

        @if ($creators->isEmpty())
            <x-states.empty title="No creators match your filters">
                @if ($search !== '' || $statusFilter !== '' || $countryFilter !== '' || $cityFilter !== '')
                    Try adjusting or clearing the search and filters above.
                @else
                    Create the first creator with the "New creator" button. Creators proposed by
                    Monitoring or Discovery also appear here.
                @endif
            </x-states.empty>
        @else
            <table class="w-full min-w-[900px]">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <x-table.th field="display_name" :sort-field="$sortField" :sort-direction="$sortDirection">Name</x-table.th>
                        <x-table.th>Platform accounts</x-table.th>
                        <x-table.th field="relationship_status" :sort-field="$sortField" :sort-direction="$sortDirection">Relationship</x-table.th>
                        <x-table.th>Geography</x-table.th>
                        <x-table.th field="created_at" :sort-field="$sortField" :sort-direction="$sortDirection">Created</x-table.th>
                        <x-table.th><span class="sr-only">Actions</span></x-table.th>
                    </tr>
                </thead>
                <tbody wire:loading.class="pointer-events-none opacity-50"
                    class="divide-y divide-gray-100 transition-opacity dark:divide-gray-800">
                    @foreach ($creators as $creator)
                        <tr wire:key="creator-{{ $creator->id }}">
                            <td class="px-5 py-4">
                                <a href="{{ route('crm.creators.show', $creator) }}"
                                    class="text-sm font-medium text-gray-800 hover:text-brand-500 dark:text-white/90 dark:hover:text-brand-400">
                                    {{ $creator->display_name }}
                                </a>
                            </td>
                            <td class="px-5 py-4">
                                @if ($creator->platformAccounts->isEmpty())
                                    <span class="text-sm text-gray-400">&mdash;</span>
                                @else
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($creator->platformAccounts as $account)
                                            <x-ui.badge color="light">{{ $account->platform->label() }} · {{ $account->handle }}</x-ui.badge>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                @if ($creator->relationship_status)
                                    <x-ui.badge color="primary">{{ $creator->relationship_status->label() }}</x-ui.badge>
                                @else
                                    <span class="text-sm text-gray-400">&mdash;</span>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                @if ($creator->geoAttribution !== null)
                                    {{ \App\Shared\Enums\Country::labelFor($creator->geoAttribution->country_code) }}@if ($creator->geoAttribution->city) · {{ $creator->geoAttribution->city }}@endif
                                @else
                                    <x-states.unavailable reason="No location set — add it on the creator’s profile under Geography." />
                                @endif
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ $creator->created_at->format('d.m.Y') }}
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('crm.creators.show', $creator) }}"
                                        class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                        Open
                                    </a>
                                    @can('delete', $creator)
                                        <button type="button" wire:click="confirmDelete({{ $creator->id }})"
                                            class="text-sm font-medium text-error-500 hover:text-error-600">
                                            Delete
                                        </button>
                                    @endcan
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
                    Showing {{ $creators->count() }} of {{ $creators->total() }} creators
                </p>
                {{ $creators->links() }}
            </div>
        </x-slot:footer>
    </x-table.container>

    {{-- Create modal --}}
    @if ($showForm)
        <x-ui.modal title="New creator" close-action="cancelForm">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <x-form.label for="display_name" required>Display name</x-form.label>
                    <x-form.input id="display_name" wire:model="display_name"
                        :error="$errors->has('display_name')" placeholder="Creator name" />
                    <x-form.error for="display_name" />
                </div>

                <div>
                    <x-form.label for="primary_language">Primary language</x-form.label>
                    <x-form.input id="primary_language" wire:model="primary_language"
                        :error="$errors->has('primary_language')" placeholder="e.g. de" />
                    <x-form.error for="primary_language" />
                </div>

                <div>
                    <x-form.label for="relationship_status">Relationship status</x-form.label>
                    <x-form.select id="relationship_status" wire:model="relationship_status"
                        :error="$errors->has('relationship_status')">
                        <option value="">No status</option>
                        @foreach ($statuses as $statusOption)
                            <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.error for="relationship_status" />
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelForm" wire:loading.attr="disabled">
                    Cancel
                </x-ui.button>
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Create creator</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Delete confirmation --}}
    @if ($confirmingDeleteId !== null)
        <x-ui.confirm-modal title="Delete creator?" confirm-action="delete" cancel-action="cancelDelete"
            confirm-label="Delete creator">
            Deleting a creator is permanent. Creators with monitoring history, campaign roster
            entries, or shipments cannot be deleted — remove those records first.
        </x-ui.confirm-modal>
    @endif
</div>
