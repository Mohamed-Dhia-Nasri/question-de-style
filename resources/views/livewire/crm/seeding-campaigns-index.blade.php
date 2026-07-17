<div>
    <x-table.container>
        <x-slot:header>
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative grow sm:max-w-xs">
                    <x-form.input wire:model.live.debounce.300ms="search" type="search"
                        placeholder="Search seeding runs…" aria-label="Search seeding runs" />
                </div>

                <div class="w-full sm:w-44">
                    <x-form.select wire:model.live="typeFilter" aria-label="Filter by seeding type">
                        <option value="">All seeding types</option>
                        @foreach ($types as $typeOption)
                            <option value="{{ $typeOption->value }}">{{ $typeOption->label() }}</option>
                        @endforeach
                    </x-form.select>
                </div>

                <div class="w-full sm:w-44">
                    <x-form.select wire:model.live="statusFilter" aria-label="Filter by status">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $statusOption)
                            <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
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

                @can('create', \App\Modules\CRM\Models\SeedingCampaign::class)
                    <x-ui.button wire:click="create">New seeding run</x-ui.button>
                @endcan
            </div>
        </x-slot:header>

        @if ($seedingCampaigns->isEmpty())
            @if ($search !== '' || $statusFilter !== '' || $typeFilter !== '')
                <x-states.empty title="No seeding runs match your filters">
                    Try adjusting or clearing the search and filters above.
                </x-states.empty>
            @else
                <x-states.empty title="No seeding runs yet">
                    A seeding run sends products to selected creators — on its own or as part of a
                    campaign. You need a brand first.
                    <x-slot:action>
                        @can('create', \App\Modules\CRM\Models\SeedingCampaign::class)
                            <x-ui.button size="sm" wire:click="create">New seeding run</x-ui.button>
                        @endcan
                    </x-slot:action>
                </x-states.empty>
            @endif
        @else
            <table class="w-full min-w-[900px]">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <x-table.th field="name" :sort-field="$sortField" :sort-direction="$sortDirection">Name</x-table.th>
                        <x-table.th field="seeding_type" :sort-field="$sortField" :sort-direction="$sortDirection">Seeding type</x-table.th>
                        <x-table.th>Brand</x-table.th>
                        <x-table.th>Product</x-table.th>
                        <x-table.th field="status" :sort-field="$sortField" :sort-direction="$sortDirection">Status</x-table.th>
                        <x-table.th>Shipments</x-table.th>
                        <x-table.th><span class="sr-only">Actions</span></x-table.th>
                    </tr>
                </thead>
                <tbody wire:loading.class="pointer-events-none opacity-50"
                    class="divide-y divide-gray-100 transition-opacity dark:divide-gray-800">
                    @foreach ($seedingCampaigns as $seeding)
                        <tr wire:key="seeding-{{ $seeding->id }}">
                            <td class="px-5 py-4">
                                <a href="{{ route('crm.seeding.show', $seeding) }}"
                                    class="text-sm font-medium text-gray-800 hover:text-brand-500 dark:text-white/90 dark:hover:text-brand-400">
                                    {{ $seeding->name }}
                                </a>
                            </td>
                            <td class="px-5 py-4"><x-ui.badge color="light">{{ $seeding->seeding_type->label() }}</x-ui.badge></td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $seeding->brand->name }}</td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $seeding->product?->name ?? '—' }}</td>
                            <td class="px-5 py-4"><x-ui.badge color="primary">{{ $seeding->status->label() }}</x-ui.badge></td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $seeding->shipments_count }}</td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('crm.seeding.show', $seeding) }}"
                                        class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Open</a>
                                    @can('update', $seeding)
                                        <button type="button" wire:click="edit({{ $seeding->id }})"
                                            class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Edit</button>
                                    @endcan
                                    @can('delete', $seeding)
                                        <button type="button" wire:click="confirmDelete({{ $seeding->id }})"
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
                    Showing {{ $seedingCampaigns->count() }} of {{ $seedingCampaigns->total() }} seeding runs
                </p>
                {{ $seedingCampaigns->links() }}
            </div>
        </x-slot:footer>
    </x-table.container>

    @if ($showForm)
        <x-ui.modal :title="$editingSeedingId ? 'Edit seeding run' : 'New seeding run'" close-action="cancelForm">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <x-form.label for="seeding_name" required>Name</x-form.label>
                    <x-form.input id="seeding_name" wire:model="seeding_name" :error="$errors->has('seeding_name')" />
                    <x-form.error for="seeding_name" />
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div x-data="{ s: @js($seeding_type), map: @js($typeDescriptions) }">
                        <x-form.label for="seeding_type" required>Seeding type</x-form.label>
                        <x-form.select id="seeding_type" wire:model="seeding_type" x-on:change="s = $event.target.value"
                            :error="$errors->has('seeding_type')">
                            <option value="">Select a seeding type…</option>
                            @foreach ($types as $typeOption)
                                <option value="{{ $typeOption->value }}">{{ $typeOption->label() }}</option>
                            @endforeach
                        </x-form.select>
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400" x-text="map[s] ?? ''"></p>
                        <x-form.error for="seeding_type" />
                    </div>

                    <div x-data="{ s: @js($seeding_status), map: @js($statusDescriptions) }">
                        <x-form.label for="seeding_status" required>Status</x-form.label>
                        <x-form.select id="seeding_status" wire:model="seeding_status" x-on:change="s = $event.target.value"
                            :error="$errors->has('seeding_status')">
                            @foreach ($statuses as $statusOption)
                                <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                            @endforeach
                        </x-form.select>
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400" x-text="map[s] ?? ''"></p>
                        <x-form.error for="seeding_status" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="seeding_brand_id" required>Brand</x-form.label>
                        <x-form.select id="seeding_brand_id" wire:model="seeding_brand_id" :error="$errors->has('seeding_brand_id')">
                            <option value="">Select a brand…</option>
                            @foreach ($brands as $brandOption)
                                <option value="{{ $brandOption->id }}">{{ $brandOption->name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-form.error for="seeding_brand_id" />
                    </div>

                    <div>
                        <x-form.label for="seeding_product_id">Primary product</x-form.label>
                        <x-form.select id="seeding_product_id" wire:model="seeding_product_id" :error="$errors->has('seeding_product_id')">
                            <option value="">No primary product</option>
                            @foreach ($products as $productOption)
                                <option value="{{ $productOption->id }}">{{ $productOption->name }}</option>
                            @endforeach
                        </x-form.select>
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            Optional default — each shipment picks its own product.
                        </p>
                        <x-form.error for="seeding_product_id" />
                    </div>
                </div>

                <div>
                    <x-form.label for="seeding_campaign_id">Parent campaign</x-form.label>
                    <x-form.select id="seeding_campaign_id" wire:model="seeding_campaign_id" :error="$errors->has('seeding_campaign_id')">
                        <option value="">No parent campaign</option>
                        @foreach ($campaigns as $campaignOption)
                            <option value="{{ $campaignOption->id }}">{{ $campaignOption->name }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.error for="seeding_campaign_id" />
                </div>

                <div>
                    <x-form.label for="seeding_spend">Spend ({{ app(\App\Shared\Support\TenantCurrency::class)->code() }})</x-form.label>
                    <x-form.input id="seeding_spend" wire:model="seeding_spend" type="number" step="0.01" min="0"
                        :error="$errors->has('seeding_spend')" />
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                        What you actually paid — used for the cost-per-result numbers on Results.
                    </p>
                    <x-form.error for="seeding_spend" />
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelForm" wire:loading.attr="disabled">Cancel</x-ui.button>
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editingSeedingId ? 'Save changes' : 'Create seeding run' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($confirmingDeleteId !== null)
        <x-ui.confirm-modal title="Delete seeding run?" confirm-action="delete" cancel-action="cancelDelete"
            confirm-label="Delete seeding run">
            Seeding runs referenced by shipments or documents cannot be deleted. The action is recorded in the audit log.
        </x-ui.confirm-modal>
    @endif
</div>
