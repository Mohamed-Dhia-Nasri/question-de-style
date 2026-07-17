<div>
    <x-table.container>
        <x-slot:header>
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative grow sm:max-w-xs">
                    <x-form.input wire:model.live.debounce.300ms="search" type="search"
                        placeholder="Search campaigns…" aria-label="Search campaigns" />
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

                @can('create', \App\Modules\CRM\Models\Campaign::class)
                    <x-ui.button wire:click="create">New campaign</x-ui.button>
                @endcan
            </div>
        </x-slot:header>

        @if ($campaigns->isEmpty())
            @if ($search !== '' || $statusFilter !== '')
                <x-states.empty title="No campaigns match your filters">
                    Try adjusting or clearing the search and filters above.
                </x-states.empty>
            @else
                <x-states.empty title="No campaigns yet">
                    A campaign plans and measures work for one brand over a time period. You need
                    a client and a brand first.
                    <x-slot:action>
                        @can('create', \App\Modules\CRM\Models\Campaign::class)
                            <x-ui.button size="sm" wire:click="create">New campaign</x-ui.button>
                        @endcan
                    </x-slot:action>
                </x-states.empty>
            @endif
        @else
            <table class="w-full min-w-[900px]">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <x-table.th field="name" :sort-field="$sortField" :sort-direction="$sortDirection">Name</x-table.th>
                        <x-table.th>Brand</x-table.th>
                        <x-table.th field="status" :sort-field="$sortField" :sort-direction="$sortDirection">Status</x-table.th>
                        <x-table.th field="start_at" :sort-field="$sortField" :sort-direction="$sortDirection">Dates</x-table.th>
                        <x-table.th>Creators</x-table.th>
                        <x-table.th><span class="sr-only">Actions</span></x-table.th>
                    </tr>
                </thead>
                <tbody wire:loading.class="pointer-events-none opacity-50"
                    class="divide-y divide-gray-100 transition-opacity dark:divide-gray-800">
                    @foreach ($campaigns as $campaign)
                        <tr wire:key="campaign-{{ $campaign->id }}">
                            <td class="px-5 py-4">
                                <a href="{{ route('crm.campaigns.show', $campaign) }}"
                                    class="text-sm font-medium text-gray-800 hover:text-brand-500 dark:text-white/90 dark:hover:text-brand-400">
                                    {{ $campaign->name }}
                                </a>
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $campaign->brand->name }}</td>
                            <td class="px-5 py-4"><x-ui.badge color="primary">{{ $campaign->status->label() }}</x-ui.badge></td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ $campaign->start_at?->format('d.m.Y') ?? '—' }} – {{ $campaign->end_at?->format('d.m.Y') ?? '—' }}
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $campaign->creators_count }}</td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('crm.campaigns.show', $campaign) }}"
                                        class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Open</a>
                                    @can('update', $campaign)
                                        <button type="button" wire:click="edit({{ $campaign->id }})"
                                            class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Edit</button>
                                    @endcan
                                    @can('delete', $campaign)
                                        <button type="button" wire:click="confirmDelete({{ $campaign->id }})"
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
                    Showing {{ $campaigns->count() }} of {{ $campaigns->total() }} campaigns
                </p>
                {{ $campaigns->links() }}
            </div>
        </x-slot:footer>
    </x-table.container>

    @if ($showForm)
        <x-ui.modal :title="$editingCampaignId ? 'Edit campaign' : 'New campaign'" close-action="cancelForm">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <x-form.label for="campaign_name" required>Name</x-form.label>
                    <x-form.input id="campaign_name" wire:model="campaign_name" :error="$errors->has('campaign_name')" />
                    <x-form.error for="campaign_name" />
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="campaign_brand_id" required>Brand</x-form.label>
                        <x-form.select id="campaign_brand_id" wire:model="campaign_brand_id" :error="$errors->has('campaign_brand_id')">
                            <option value="">Select a brand…</option>
                            @foreach ($brands as $brandOption)
                                <option value="{{ $brandOption->id }}">{{ $brandOption->name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-form.error for="campaign_brand_id" />
                    </div>

                    @if ($editingCampaignId !== null)
                        <div x-data="{ s: @js($campaign_status), map: @js($statusDescriptions) }">
                            <x-form.label for="campaign_status" required>Status</x-form.label>
                            <x-form.select id="campaign_status" wire:model="campaign_status"
                                x-on:change="s = $event.target.value" :error="$errors->has('campaign_status')">
                                @foreach ($statuses as $statusOption)
                                    <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                                @endforeach
                            </x-form.select>
                            <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400" x-text="map[s] ?? ''"></p>
                            <x-form.error for="campaign_status" />
                        </div>
                    @endif
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="campaign_start_at">Starts</x-form.label>
                        <x-form.input id="campaign_start_at" wire:model="campaign_start_at" type="datetime-local"
                            :error="$errors->has('campaign_start_at')" />
                        <x-form.error for="campaign_start_at" />
                    </div>

                    <div>
                        <x-form.label for="campaign_end_at">Ends</x-form.label>
                        <x-form.input id="campaign_end_at" wire:model="campaign_end_at" type="datetime-local"
                            :error="$errors->has('campaign_end_at')" />
                        <x-form.error for="campaign_end_at" />
                    </div>
                </div>

                @if ($editingCampaignId !== null)
                    <div>
                        <x-form.label for="campaign_spend">Spend ({{ app(\App\Shared\Support\TenantCurrency::class)->code() }})</x-form.label>
                        <x-form.input id="campaign_spend" wire:model="campaign_spend" type="number" step="0.01" min="0"
                            :error="$errors->has('campaign_spend')" />
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            What you actually paid — used for the cost-per-result numbers on Results.
                        </p>
                        <x-form.error for="campaign_spend" />
                    </div>
                @else
                    <p class="text-xs text-gray-500 dark:text-gray-400">New campaigns start as a draft — you can change the status and record spend once it’s set up.</p>
                @endif
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelForm" wire:loading.attr="disabled">Cancel</x-ui.button>
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editingCampaignId ? 'Save changes' : 'Create campaign' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($confirmingDeleteId !== null)
        <x-ui.confirm-modal title="Delete campaign?" confirm-action="delete" cancel-action="cancelDelete"
            confirm-label="Delete campaign">
            Campaigns referenced by seeding runs, mentions, or other records cannot be deleted.
            The action is recorded in the audit log.
        </x-ui.confirm-modal>
    @endif
</div>
