<div>
    <x-table.container>
        <x-slot:header>
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative grow sm:max-w-xs">
                    <x-form.input wire:model.live.debounce.300ms="search" type="search"
                        placeholder="Search brands…" aria-label="Search brands" />
                </div>

                <div class="w-full sm:w-28">
                    <x-form.select wire:model.live="perPage" aria-label="Rows per page">
                        <option value="10">10 / page</option>
                        <option value="25">25 / page</option>
                        <option value="50">50 / page</option>
                    </x-form.select>
                </div>

                <div class="grow"></div>

                @can('create', \App\Modules\CRM\Models\Brand::class)
                    <x-ui.button wire:click="create">New brand</x-ui.button>
                @endcan
            </div>
        </x-slot:header>

        @if ($brands->isEmpty())
            @if ($search !== '')
                <x-states.empty title="No brands match your search">
                    Try a different search term.
                </x-states.empty>
            @else
                <x-states.empty title="No brands yet">
                    A brand belongs to a client; campaigns, products, and seeding runs all attach
                    to a brand. Create the client first if you haven’t.
                    <x-slot:action>
                        @can('create', \App\Modules\CRM\Models\Brand::class)
                            <x-ui.button size="sm" wire:click="create">New brand</x-ui.button>
                        @endcan
                    </x-slot:action>
                </x-states.empty>
            @endif
        @else
            <table class="w-full min-w-[800px]">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <x-table.th field="name" :sort-field="$sortField" :sort-direction="$sortDirection">Name</x-table.th>
                        <x-table.th>Client</x-table.th>
                        <x-table.th>Sector</x-table.th>
                        <x-table.th>Aliases</x-table.th>
                        <x-table.th>Products</x-table.th>
                        <x-table.th><span class="sr-only">Actions</span></x-table.th>
                    </tr>
                </thead>
                <tbody wire:loading.class="pointer-events-none opacity-50"
                    class="divide-y divide-gray-100 transition-opacity dark:divide-gray-800">
                    @foreach ($brands as $brand)
                        <tr wire:key="brand-{{ $brand->id }}">
                            <td class="px-5 py-4">
                                <a href="{{ route('crm.brands.show', $brand) }}" class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $brand->name }}</a>
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $brand->client->name }}</td>
                            <td class="px-5 py-4">
                                @if ($brand->sector)
                                    <x-ui.badge color="light">{{ $brand->sector->label() }}</x-ui.badge>
                                @else
                                    <span class="text-sm text-gray-400">&mdash;</span>
                                @endif
                            </td>
                            <td class="max-w-xs truncate px-5 py-4 text-sm text-gray-500 dark:text-gray-400"
                                title="{{ implode(', ', $brand->aliases ?? []) }}">
                                {{ empty($brand->aliases) ? '—' : implode(', ', $brand->aliases) }}
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $brand->products_count }}</td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    @can('update', $brand)
                                        <button type="button" wire:click="edit({{ $brand->id }})"
                                            class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Edit</button>
                                    @endcan
                                    @can('delete', $brand)
                                        <button type="button" wire:click="confirmDelete({{ $brand->id }})"
                                            class="text-sm font-medium text-error-500 hover:text-error-600">Delete</button>
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
                    Showing {{ $brands->count() }} of {{ $brands->total() }} brands
                </p>
                {{ $brands->links() }}
            </div>
        </x-slot:footer>
    </x-table.container>

    @if ($showForm)
        <x-ui.modal :title="$editingBrandId ? 'Edit brand' : 'New brand'" close-action="cancelForm">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <x-form.label for="brand_client_id" required>Client</x-form.label>
                    <x-form.select id="brand_client_id" wire:model="brand_client_id" :error="$errors->has('brand_client_id')">
                        <option value="">Select a client…</option>
                        @foreach ($clients as $clientOption)
                            <option value="{{ $clientOption->id }}">{{ $clientOption->name }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.error for="brand_client_id" />
                    @can('create', \App\Modules\CRM\Models\Client::class)
                        <button type="button" wire:click="openInlineCreate('client')"
                            class="mt-1.5 text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">+ New client</button>
                    @endcan
                </div>

                <div>
                    <x-form.label for="brand_name" required>Name</x-form.label>
                    <x-form.input id="brand_name" wire:model="brand_name" :error="$errors->has('brand_name')" />
                    <x-form.error for="brand_name" />
                </div>

                <div>
                    <x-form.label for="brand_sector">Sector</x-form.label>
                    <x-form.select id="brand_sector" wire:model="brand_sector" :error="$errors->has('brand_sector')">
                        <option value="">No sector</option>
                        @foreach ($sectors as $sectorOption)
                            <option value="{{ $sectorOption->value }}">{{ $sectorOption->label() }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.error for="brand_sector" />
                </div>

                <div>
                    <x-form.label for="brand_aliases">Aliases</x-form.label>
                    <x-form.textarea id="brand_aliases" wire:model="brand_aliases" rows="3"
                        :error="$errors->has('brand_aliases')" placeholder="One alias per line — names, spellings, handles" />
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                        Aliases feed the monitored-subject terms for this brand.
                    </p>
                    <x-form.error for="brand_aliases" />
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelForm" wire:loading.attr="disabled">Cancel</x-ui.button>
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editingBrandId ? 'Save changes' : 'Create brand' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-crm.inline-create :type="$inlineCreate" />

    @if ($confirmingDeleteId !== null)
        <x-ui.confirm-modal title="Delete brand?" confirm-action="delete" cancel-action="cancelDelete"
            confirm-label="Delete brand">
            Brands with products, campaigns, or monitoring records cannot be deleted. The action is
            recorded in the audit log.
        </x-ui.confirm-modal>
    @endif
</div>
