<div x-data="{ tab: ['products','campaigns','seeding'].includes(window.location.hash.slice(1)) ? window.location.hash.slice(1) : 'products' }"
    x-init="$watch('tab', value => history.replaceState(null, '', '#' + value))">
    <div class="mb-4 flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-800" role="tablist">
        <button type="button" role="tab" :aria-selected="tab === 'products'" x-on:click="tab = 'products'"
            :class="tab === 'products' ? 'border-brand-500 text-brand-500 dark:text-brand-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
            class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium">
            Products ({{ $products->count() }})
        </button>
        <button type="button" role="tab" :aria-selected="tab === 'campaigns'" x-on:click="tab = 'campaigns'"
            :class="tab === 'campaigns' ? 'border-brand-500 text-brand-500 dark:text-brand-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
            class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium">
            Campaigns ({{ $campaigns->count() }})
        </button>
        <button type="button" role="tab" :aria-selected="tab === 'seeding'" x-on:click="tab = 'seeding'"
            :class="tab === 'seeding' ? 'border-brand-500 text-brand-500 dark:text-brand-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
            class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium">
            Seeding runs ({{ $seedingRuns->count() }})
        </button>
    </div>

    <div x-show="tab === 'products'" x-cloak class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @if ($products->isEmpty())
            <x-states.empty title="No products yet">
                Products are what you send to creators on a seeding run.
                <x-slot:action>
                    <a href="{{ route('crm.products.index') }}" class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Go to Products &rarr;</a>
                </x-slot:action>
            </x-states.empty>
        @else
            <div class="max-w-full overflow-x-auto">
                <table class="w-full min-w-[600px]">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-800">
                            <x-table.th>Name</x-table.th>
                            <x-table.th>Product variant</x-table.th>
                            <x-table.th>Unit value</x-table.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($products as $product)
                            <tr wire:key="brand-product-{{ $product->id }}">
                                <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $product->name }}</td>
                                <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $product->variant ?? '—' }}</td>
                                <td class="px-5 py-4">
                                    @if ($product->unit_value)
                                        <span class="text-sm text-gray-800 dark:text-white/90">
                                            {{ number_format($product->unit_value->amount, 2, ',', '.') }} {{ app(\App\Shared\Support\TenantCurrency::class)->code() }}
                                        </span>
                                        <x-metric.tier-badge :tier="$product->unit_value->tier" />
                                    @else
                                        <span class="text-sm text-gray-400">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div x-show="tab === 'campaigns'" x-cloak class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @if ($campaigns->isEmpty())
            <x-states.empty title="No campaigns yet">
                Campaigns bring together this brand’s creators, seeding runs, and results.
                <x-slot:action>
                    <a href="{{ route('crm.campaigns.index') }}" class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Go to Campaigns &rarr;</a>
                </x-slot:action>
            </x-states.empty>
        @else
            <div class="max-w-full overflow-x-auto">
                <table class="w-full min-w-[600px]">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-800">
                            <x-table.th>Name</x-table.th>
                            <x-table.th>Status</x-table.th>
                            <x-table.th>Creators</x-table.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($campaigns as $campaign)
                            <tr wire:key="brand-campaign-{{ $campaign->id }}">
                                <td class="px-5 py-4">
                                    <a href="{{ route('crm.campaigns.show', $campaign) }}" class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                        {{ $campaign->name }}
                                    </a>
                                </td>
                                <td class="px-5 py-4"><x-ui.badge color="primary">{{ $campaign->status->label() }}</x-ui.badge></td>
                                <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $campaign->creators_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div x-show="tab === 'seeding'" x-cloak class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @if ($seedingRuns->isEmpty())
            <x-states.empty title="No seeding runs yet">
                Seeding runs send this brand’s products to creators.
                <x-slot:action>
                    <a href="{{ route('crm.seeding.index') }}" class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Go to Seeding runs &rarr;</a>
                </x-slot:action>
            </x-states.empty>
        @else
            <div class="max-w-full overflow-x-auto">
                <table class="w-full min-w-[700px]">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-800">
                            <x-table.th>Name</x-table.th>
                            <x-table.th>Seeding type</x-table.th>
                            <x-table.th>Status</x-table.th>
                            <x-table.th>Shipments</x-table.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($seedingRuns as $run)
                            <tr wire:key="brand-seeding-{{ $run->id }}">
                                <td class="px-5 py-4">
                                    <a href="{{ route('crm.seeding.show', $run) }}" class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                        {{ $run->name }}
                                    </a>
                                </td>
                                <td class="px-5 py-4"><x-ui.badge color="light">{{ $run->seeding_type->label() }}</x-ui.badge></td>
                                <td class="px-5 py-4"><x-ui.badge color="primary">{{ $run->status->label() }}</x-ui.badge></td>
                                <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $run->shipments_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
