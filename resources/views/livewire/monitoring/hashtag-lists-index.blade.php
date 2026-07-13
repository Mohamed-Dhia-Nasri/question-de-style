<div>
    <x-table.container>
        <x-slot:header>
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative grow sm:max-w-xs">
                    <x-form.input wire:model.live.debounce.300ms="search" type="search"
                        placeholder="Search hashtags…" aria-label="Search hashtags" />
                </div>

                <div class="w-full sm:w-28">
                    <x-form.select wire:model.live="perPage" aria-label="Rows per page">
                        <option value="10">10 / page</option>
                        <option value="25">25 / page</option>
                        <option value="50">50 / page</option>
                    </x-form.select>
                </div>

                <div class="grow"></div>

                @can('create', \App\Modules\Monitoring\Models\HashtagList::class)
                    <x-ui.button wire:click="create">New hashtag</x-ui.button>
                @endcan
            </div>
        </x-slot:header>

        @if ($entries->isEmpty())
            <x-states.empty title="No hashtags registered">
                Registered hashtags feed content-to-campaign matching as attribution
                evidence — a hashtag alone never proves a paid or seeded post.
            </x-states.empty>
        @else
            <table class="w-full min-w-[820px]">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <x-table.th field="normalized" :sort-field="$sortField" :sort-direction="$sortDirection">Hashtag</x-table.th>
                        <x-table.th field="scope" :sort-field="$sortField" :sort-direction="$sortDirection">Scope</x-table.th>
                        <x-table.th>Applies to</x-table.th>
                        <x-table.th field="content_count" :sort-field="$sortField" :sort-direction="$sortDirection">Seen in</x-table.th>
                        <x-table.th field="active" :sort-field="$sortField" :sort-direction="$sortDirection">Status</x-table.th>
                        <x-table.th field="created_at" :sort-field="$sortField" :sort-direction="$sortDirection">Created</x-table.th>
                        <x-table.th><span class="sr-only">Actions</span></x-table.th>
                    </tr>
                </thead>
                <tbody wire:loading.class="pointer-events-none opacity-50"
                    class="divide-y divide-gray-100 transition-opacity dark:divide-gray-800">
                    @foreach ($entries as $entry)
                        <tr wire:key="hashtag-{{ $entry->id }}">
                            <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $entry->hashtag }}</td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $entry->scope->value }}</td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                @if ($entry->scope === \App\Platform\Enrichment\Support\HashtagScope::Campaign)
                                    {{ $entry->campaign?->name ?? '—' }}
                                @elseif ($entry->scope === \App\Platform\Enrichment\Support\HashtagScope::Product)
                                    {{ $entry->brand?->name ?? '—' }} · {{ $entry->product_label }}
                                @elseif ($entry->scope === \App\Platform\Enrichment\Support\HashtagScope::Brand)
                                    {{ $entry->brand?->name ?? '—' }}
                                @else
                                    Agency-wide
                                @endif
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ number_format((int) $entry->content_count) }} {{ (int) $entry->content_count === 1 ? 'post' : 'posts' }}
                            </td>
                            <td class="px-5 py-4">
                                <x-ui.badge :color="$entry->active ? 'success' : 'warning'" size="sm">
                                    {{ $entry->active ? 'Active' : 'Inactive' }}
                                </x-ui.badge>
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $entry->created_at->format('d.m.Y') }}</td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    @can('update', $entry)
                                        <button type="button" wire:click="toggleActive({{ $entry->id }})"
                                            class="text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                                            {{ $entry->active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                        <button type="button" wire:click="edit({{ $entry->id }})"
                                            class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Edit</button>
                                    @endcan
                                    @can('delete', $entry)
                                        <button type="button" wire:click="confirmDelete({{ $entry->id }})"
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
                    Showing {{ $entries->count() }} of {{ $entries->total() }} hashtags
                </p>
                {{ $entries->links() }}
            </div>
        </x-slot:footer>
    </x-table.container>

    @if ($showForm)
        <x-ui.modal :title="$editingHashtagListId ? 'Edit hashtag' : 'New hashtag'" close-action="cancelForm">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <x-form.label for="hashtag_value" required>Hashtag</x-form.label>
                    <x-form.input id="hashtag_value" wire:model="hashtag_value"
                        :error="$errors->has('hashtag_value')" placeholder="#summerglow" />
                    <x-form.error for="hashtag_value" />
                </div>

                <div>
                    <x-form.label for="hashtag_scope" required>Scope</x-form.label>
                    <x-form.select id="hashtag_scope" wire:model.live="hashtag_scope"
                        :error="$errors->has('hashtag_scope')">
                        <option value="">Select a scope…</option>
                        @foreach ($scopes as $scope)
                            <option value="{{ $scope->value }}">{{ $scope->value }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.error for="hashtag_scope" />
                </div>

                @if ($hashtag_scope === \App\Platform\Enrichment\Support\HashtagScope::Campaign->value)
                    <div>
                        <x-form.label for="hashtag_campaign_id" required>Campaign</x-form.label>
                        <x-form.select id="hashtag_campaign_id" wire:model="hashtag_campaign_id"
                            :error="$errors->has('hashtag_campaign_id')">
                            <option value="">Select a campaign…</option>
                            @foreach ($campaigns as $campaign)
                                <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-form.error for="hashtag_campaign_id" />
                    </div>
                @endif

                @if (in_array($hashtag_scope, [\App\Platform\Enrichment\Support\HashtagScope::Brand->value, \App\Platform\Enrichment\Support\HashtagScope::Product->value], true))
                    <div>
                        <x-form.label for="hashtag_brand_id" required>Brand</x-form.label>
                        <x-form.select id="hashtag_brand_id" wire:model="hashtag_brand_id"
                            :error="$errors->has('hashtag_brand_id')">
                            <option value="">Select a brand…</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-form.error for="hashtag_brand_id" />
                    </div>
                @endif

                @if ($hashtag_scope === \App\Platform\Enrichment\Support\HashtagScope::Product->value)
                    <div>
                        <x-form.label for="hashtag_product_label" required>Product</x-form.label>
                        <x-form.input id="hashtag_product_label" wire:model="hashtag_product_label"
                            :error="$errors->has('hashtag_product_label')" placeholder="e.g. Glow Serum" />
                        <x-form.error for="hashtag_product_label" />
                    </div>
                @endif
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelForm" wire:loading.attr="disabled">Cancel</x-ui.button>
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editingHashtagListId ? 'Save changes' : 'Register hashtag' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($confirmingDeleteId !== null)
        <x-ui.confirm-modal title="Delete hashtag?" confirm-action="delete" cancel-action="cancelDelete"
            confirm-label="Delete hashtag">
            Prefer deactivating: deleting unlinks any human-resolved ambiguous
            matches that pointed at this entry. The action is recorded in the
            audit log.
        </x-ui.confirm-modal>
    @endif
</div>
