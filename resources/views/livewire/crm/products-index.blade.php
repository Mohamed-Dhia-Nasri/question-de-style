<div>
    <x-table.container>
        <x-slot:header>
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative grow sm:max-w-xs">
                    <x-form.input wire:model.live.debounce.300ms="search" type="search"
                        placeholder="Search name or SKU…" aria-label="Search products" />
                </div>

                <div class="w-full sm:w-28">
                    <x-form.select wire:model.live="perPage" aria-label="Rows per page">
                        <option value="10">10 / page</option>
                        <option value="25">25 / page</option>
                        <option value="50">50 / page</option>
                    </x-form.select>
                </div>

                <div class="grow"></div>

                @can('create', \App\Modules\CRM\Models\Product::class)
                    <x-ui.button wire:click="create">New product</x-ui.button>
                @endcan
            </div>
        </x-slot:header>

        @if ($products->isEmpty())
            @if ($search !== '')
                <x-states.empty title="No products match your search">
                    Try a different search term.
                </x-states.empty>
            @else
                <x-states.empty title="No products yet">
                    Products are what you send to creators. Each product belongs to a brand.
                    <x-slot:action>
                        @can('create', \App\Modules\CRM\Models\Product::class)
                            <x-ui.button size="sm" wire:click="create">New product</x-ui.button>
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
                        <x-table.th field="sku" :sort-field="$sortField" :sort-direction="$sortDirection">SKU</x-table.th>
                        <x-table.th>Product variant</x-table.th>
                        <x-table.th>Unit value</x-table.th>
                        <x-table.th>Sector</x-table.th>
                        <x-table.th><span class="sr-only">Actions</span></x-table.th>
                    </tr>
                </thead>
                <tbody wire:loading.class="pointer-events-none opacity-50"
                    class="divide-y divide-gray-100 transition-opacity dark:divide-gray-800">
                    @foreach ($products as $product)
                        <tr wire:key="product-{{ $product->id }}">
                            <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $product->name }}</td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $product->brand->name }}</td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $product->sku ?? '—' }}</td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $product->variant ?? '—' }}</td>
                            <td class="px-5 py-4">
                                @if ($product->unit_value)
                                    <span class="text-sm text-gray-800 dark:text-white/90">
                                        {{ number_format($product->unit_value->amount, 2, ',', '.') }} {{ app(\App\Shared\Support\TenantCurrency::class)->code() }}
                                    </span>
                                    {{-- DP-001: the tier travels with the number. --}}
                                    <x-metric.tier-badge :tier="$product->unit_value->tier" />
                                @else
                                    <span class="text-sm text-gray-400">&mdash;</span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                @if ($product->category)
                                    <x-ui.badge color="light">{{ $product->category->label() }}</x-ui.badge>
                                @else
                                    <span class="text-sm text-gray-400">&mdash;</span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    @can('update', $product)
                                        <button type="button" wire:click="edit({{ $product->id }})"
                                            class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Edit</button>
                                    @endcan
                                    @can('delete', $product)
                                        <button type="button" wire:click="confirmDelete({{ $product->id }})"
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
                    Showing {{ $products->count() }} of {{ $products->total() }} products
                </p>
                {{ $products->links() }}
            </div>
        </x-slot:footer>
    </x-table.container>

    @if ($showForm)
        <x-ui.modal :title="$editingProductId ? 'Edit product' : 'New product'" close-action="cancelForm">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <x-form.label for="product_brand_id" required>Brand</x-form.label>
                    <x-form.select id="product_brand_id" wire:model="product_brand_id" :error="$errors->has('product_brand_id')">
                        <option value="">Select a brand…</option>
                        @foreach ($brands as $brandOption)
                            <option value="{{ $brandOption->id }}">{{ $brandOption->name }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.error for="product_brand_id" />
                    @can('create', \App\Modules\CRM\Models\Brand::class)
                        <button type="button" wire:click="openInlineCreate('brand')"
                            class="mt-1.5 text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">+ New brand</button>
                    @endcan
                </div>

                <div>
                    <x-form.label for="product_name" required>Name</x-form.label>
                    <x-form.input id="product_name" wire:model="product_name" :error="$errors->has('product_name')" />
                    <x-form.error for="product_name" />
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="product_sku">SKU</x-form.label>
                        <x-form.input id="product_sku" wire:model="product_sku" :error="$errors->has('product_sku')" />
                        <x-form.error for="product_sku" />
                    </div>

                    <div>
                        <x-form.label for="product_variant">Product variant</x-form.label>
                        <x-form.input id="product_variant" wire:model="product_variant"
                            :error="$errors->has('product_variant')" placeholder="Size or colour" />
                        <x-form.error for="product_variant" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="product_unit_value">Unit value ({{ app(\App\Shared\Support\TenantCurrency::class)->code() }})</x-form.label>
                        <x-form.input id="product_unit_value" wire:model="product_unit_value" type="number" step="0.01" min="0"
                            :error="$errors->has('product_unit_value')" />
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            Entered by you, so it’s used exactly as typed.
                        </p>
                        <x-form.error for="product_unit_value" />
                    </div>

                    <div>
                        <x-form.label for="product_category">Sector</x-form.label>
                        <x-form.select id="product_category" wire:model="product_category" :error="$errors->has('product_category')">
                            <option value="">No sector</option>
                            @foreach ($categories as $categoryOption)
                                <option value="{{ $categoryOption->value }}">{{ $categoryOption->label() }}</option>
                            @endforeach
                        </x-form.select>
                        <x-form.error for="product_category" />
                    </div>
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelForm" wire:loading.attr="disabled">Cancel</x-ui.button>
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editingProductId ? 'Save changes' : 'Create product' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-crm.inline-create :type="$inlineCreate"
        :clients="$clients ?? \App\Modules\CRM\Models\Client::orderBy('name')->get()"
        :new-client="$inline_new_client" />

    @if ($confirmingDeleteId !== null)
        <x-ui.confirm-modal title="Delete product?" confirm-action="delete" cancel-action="cancelDelete"
            confirm-label="Delete product">
            Products referenced by seeding runs or shipments cannot be deleted. The action is
            recorded in the audit log.
        </x-ui.confirm-modal>
    @endif
</div>
