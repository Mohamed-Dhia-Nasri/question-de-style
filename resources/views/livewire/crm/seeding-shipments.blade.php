<div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4 dark:border-gray-800">
        <div>
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Shipments</h3>
            <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                Update each shipment’s status by hand as it moves. “Posted” fills in automatically
                when content is linked to the shipment.
            </p>
        </div>

        @can('create', \App\Modules\CRM\Models\Shipment::class)
            <x-ui.button size="sm" wire:click="create">New shipment</x-ui.button>
        @endcan
    </div>

    @if ($shipments->isEmpty())
        <x-states.empty title="No shipments yet">
            A shipment sends one product to one creator on this run's roster.
            <x-slot:action>
                @can('create', \App\Modules\CRM\Models\Shipment::class)
                    <x-ui.button size="sm" wire:click="create">New shipment</x-ui.button>
                @endcan
            </x-slot:action>
        </x-states.empty>
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1000px]">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <x-table.th>Recipient</x-table.th>
                        <x-table.th>Product</x-table.th>
                        <x-table.th>Status</x-table.th>
                        <x-table.th>Tracking</x-table.th>
                        <x-table.th>Shipped / delivered</x-table.th>
                        <x-table.th>Qty</x-table.th>
                        <x-table.th>Posted</x-table.th>
                        <x-table.th>Resulting content</x-table.th>
                        <x-table.th><span class="sr-only">Actions</span></x-table.th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($shipments as $shipment)
                        <tr wire:key="shipment-{{ $shipment->id }}">
                            <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">
                                {{ $shipment->creator->display_name }}
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ $shipment->product->name }}
                                @if ($shipment->product_value_at_ship)
                                    <span class="text-theme-xs text-gray-400">
                                        ({{ number_format($shipment->product_value_at_ship->amount, 2, ',', '.') }} {{ app(\App\Shared\Support\TenantCurrency::class)->code() }}
                                        <x-metric.tier-badge :tier="$shipment->product_value_at_ship->tier" />)
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-4"><x-ui.badge color="primary">{{ $shipment->status->label() }}</x-ui.badge></td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $shipment->tracking_number ?? '—' }}</td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ $shipment->shipped_at?->format('d.m.Y') ?? '—' }} / {{ $shipment->delivered_at?->format('d.m.Y') ?? '—' }}
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $shipment->quantity ?? '—' }}</td>
                            <td class="px-5 py-4">
                                @if ($shipment->posted)
                                    <x-ui.badge color="success">Posted {{ $shipment->posted_at?->format('d.m.Y') }}</x-ui.badge>
                                @elseif ($shipment->posting_required)
                                    <x-ui.badge color="warning">Awaited</x-ui.badge>
                                @else
                                    <span class="text-sm text-gray-400">&mdash;</span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                @if ($shipment->resultingContent->isEmpty())
                                    <span class="text-sm text-gray-400">&mdash;</span>
                                @else
                                    <div class="flex flex-col gap-1">
                                        @foreach ($shipment->resultingContent as $content)
                                            <span class="flex items-center gap-2 text-theme-xs text-gray-500 dark:text-gray-400"
                                                wire:key="link-{{ $shipment->id }}-{{ $content->id }}">
                                                <x-ui.badge color="light" size="sm">{{ $content->platform->label() }}</x-ui.badge>
                                                <span class="max-w-[10rem] truncate" title="{{ $content->caption }}">
                                                    {{ $content->external_id }}
                                                </span>
                                                @can('update', $shipment)
                                                    <button type="button"
                                                        wire:click="confirmUnlink({{ $shipment->id }}, {{ $content->id }})"
                                                        class="font-medium text-error-500 hover:text-error-600">
                                                        Remove
                                                    </button>
                                                @endcan
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    @can('update', $shipment)
                                        <button type="button" wire:click="openLinkForm({{ $shipment->id }})"
                                            class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                            Link content
                                        </button>
                                        <button type="button" wire:click="edit({{ $shipment->id }})"
                                            class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                            Edit
                                        </button>
                                    @endcan
                                    @can('delete', $shipment)
                                        <button type="button" wire:click="confirmDelete({{ $shipment->id }})"
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
        </div>
    @endif

    {{-- Create / edit shipment --}}
    @if ($showForm)
        <x-ui.modal :title="$editingShipmentId ? 'Edit shipment' : 'New shipment'" close-action="cancelForm" max-width="xl">
            <form wire:submit="save" class="space-y-5">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="shipment_creator_id" required>Recipient</x-form.label>
                        <x-form.select id="shipment_creator_id" wire:model="shipment_creator_id"
                            :error="$errors->has('shipment_creator_id')">
                            <option value="">Select a seeded creator…</option>
                            @foreach ($recipients as $recipientOption)
                                <option value="{{ $recipientOption->id }}">{{ $recipientOption->display_name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-form.error for="shipment_creator_id" />
                    </div>

                    <div>
                        <x-form.label for="shipment_product_id" required>Product</x-form.label>
                        <x-form.select id="shipment_product_id" wire:model="shipment_product_id"
                            :error="$errors->has('shipment_product_id')">
                            <option value="">Select a product…</option>
                            @foreach ($products as $productOption)
                                <option value="{{ $productOption->id }}">{{ $productOption->name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-form.error for="shipment_product_id" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div x-data="{ s: @js($shipment_status), map: @js($statusDescriptions) }">
                        <x-form.label for="shipment_status" required>Status</x-form.label>
                        <x-form.select id="shipment_status" wire:model="shipment_status" x-on:change="s = $event.target.value"
                            :error="$errors->has('shipment_status')">
                            @foreach ($statuses as $statusOption)
                                <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                            @endforeach
                        </x-form.select>
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400" x-text="map[s] ?? ''"></p>
                        <x-form.error for="shipment_status" />
                    </div>

                    <div>
                        <x-form.label for="shipment_tracking_number">Tracking number</x-form.label>
                        <x-form.input id="shipment_tracking_number" wire:model="shipment_tracking_number"
                            :error="$errors->has('shipment_tracking_number')" />
                        <x-form.error for="shipment_tracking_number" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="shipment_shipped_at">Shipped at</x-form.label>
                        <x-form.input id="shipment_shipped_at" wire:model="shipment_shipped_at" type="datetime-local"
                            :error="$errors->has('shipment_shipped_at')" />
                        <x-form.error for="shipment_shipped_at" />
                    </div>

                    <div>
                        <x-form.label for="shipment_delivered_at">Delivered at</x-form.label>
                        <x-form.input id="shipment_delivered_at" wire:model="shipment_delivered_at" type="datetime-local"
                            :error="$errors->has('shipment_delivered_at')" />
                        <x-form.error for="shipment_delivered_at" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="shipment_quantity">Quantity</x-form.label>
                        <x-form.input id="shipment_quantity" wire:model="shipment_quantity" type="number" min="1"
                            :error="$errors->has('shipment_quantity')" />
                        <x-form.error for="shipment_quantity" />
                    </div>

                    <div>
                        <x-form.label for="shipment_value">Value of goods ({{ app(\App\Shared\Support\TenantCurrency::class)->code() }})</x-form.label>
                        <x-form.input id="shipment_value" wire:model="shipment_value" type="number" step="0.01" min="0"
                            :error="$errors->has('shipment_value')" />
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            What the product was worth when it shipped.
                        </p>
                        <x-form.error for="shipment_value" />
                    </div>
                </div>

                <x-form.toggle wire:model="shipment_posting_required" label="Posting agreed (gifting-with-post / paid + product)" />
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelForm" wire:loading.attr="disabled">Cancel</x-ui.button>
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editingShipmentId ? 'Save changes' : 'Create shipment' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Manual content link (XMC-002 confirm) --}}
    @if ($linkingShipmentId !== null)
        <x-ui.modal title="Link content to shipment" close-action="cancelLinkForm">
            <form wire:submit="linkContent" class="space-y-5">
                <div>
                    <x-form.label for="link_content_id" required>Content item</x-form.label>
                    <x-form.select id="link_content_id" wire:model="link_content_id" :error="$errors->has('link_content_id')">
                        <option value="">Select the recipient's content…</option>
                        @foreach ($linkableContent as $contentOption)
                            <option value="{{ $contentOption->id }}">
                                [{{ $contentOption->platform->label() }}] {{ $contentOption->external_id }} — {{ $contentOption->published_at?->format('d.m.Y') ?? 'undated' }}
                            </option>
                        @endforeach
                    </x-form.select>
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                        Link a post to this shipment when the creator publishes it — that’s how
                        results and “posted” status are counted.
                    </p>
                    <x-form.error for="link_content_id" />
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelLinkForm" wire:loading.attr="disabled">Cancel</x-ui.button>
                <x-ui.button wire:click="linkContent" wire:loading.attr="disabled" wire:target="linkContent">
                    <span wire:loading.remove wire:target="linkContent">Link content</span>
                    <span wire:loading wire:target="linkContent">Linking…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Unlink confirmation (XMC-002 deny) --}}
    @if ($unlinkShipmentId !== null)
        <x-ui.confirm-modal title="Remove content link?" confirm-action="unlink" cancel-action="cancelUnlink"
            confirm-label="Remove link">
            This denies the content↔shipment match: the posted state is recomputed and the campaign
            attribution on the content's mentions is retracted. The action is recorded in the audit log.
        </x-ui.confirm-modal>
    @endif

    {{-- Delete confirmation --}}
    @if ($confirmingDeleteId !== null)
        <x-ui.confirm-modal title="Delete shipment?" confirm-action="delete" cancel-action="cancelDelete"
            confirm-label="Delete shipment">
            This removes the shipment and its content links (the content itself is untouched).
            The action is recorded in the audit log.
        </x-ui.confirm-modal>
    @endif
</div>
