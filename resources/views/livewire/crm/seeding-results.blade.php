<div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-800">
        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Results</h3>
        <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
            Results for this seeding run.
        </p>
        <x-metric.tier-legend class="mt-1" />
    </div>

    <div class="p-6">
        {{-- AC-M3-014: run totals — counts are real zeros, PUBLIC/DERIVED sums stay tiered --}}
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                <p class="text-theme-xs uppercase text-gray-400">Shipments / posted</p>
                {{-- A count of zero IS a real measurement — rendered, not "unavailable". --}}
                <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">
                    {{ number_format((int) ($totals->shipments ?? 0)) }} / {{ number_format((int) ($totals->posted_count ?? 0)) }}
                </p>
            </div>
            <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                <p class="text-theme-xs uppercase text-gray-400">Creators reached</p>
                <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{{ number_format($totals->creators_reached) }}</p>
            </div>
            <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                <p class="text-theme-xs uppercase text-gray-400">Content items</p>
                <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{{ number_format((int) ($totals->content_count ?? 0)) }}</p>
            </div>
            <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                <p class="text-theme-xs uppercase text-gray-400">Views</p>
                <div class="mt-1">
                    @if ($totals->total_views !== null)
                        <span class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ number_format((float) $totals->total_views) }}</span>
                        <x-metric.tier-badge tier="PUBLIC" />
                    @else
                        <x-states.unavailable reason="No observed views for this seeding run — never shown as zero." />
                    @endif
                </div>
            </div>
            <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                <p class="text-theme-xs uppercase text-gray-400">Engagement</p>
                <div class="mt-1">
                    @if ($totals->total_engagement !== null)
                        <span class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ number_format((float) $totals->total_engagement) }}</span>
                        <x-metric.tier-badge tier="DERIVED" />
                    @else
                        <x-states.unavailable reason="No observed engagement components for this seeding run — never shown as zero." />
                    @endif
                </div>
            </div>
            <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                <p class="text-theme-xs uppercase text-gray-400">Estimated reach</p>
                <div class="mt-1">
                    @if ($totals->total_estimated_reach !== null)
                        <span class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ number_format((float) $totals->total_estimated_reach) }}</span>
                        <x-metric.tier-badge tier="ESTIMATED" />
                    @else
                        <x-states.unavailable reason="No estimated reach yet — reach needs an active reach setting (Settings → Reach)." />
                    @endif
                </div>
            </div>
            <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                <p class="text-theme-xs uppercase text-gray-400">Spend ({{ app(\App\Shared\Support\TenantCurrency::class)->code() }})</p>
                <div class="mt-1">
                    <x-metric.value :metric="$seedingCampaign->spend" :decimals="2"
                        reason="No spend entered for this seeding run yet — add it when editing the run." />
                </div>
            </div>
        </div>

        {{-- AC-M3-015: EMV (ESTIMATED, model disclosed) + display-time CPE/CPM (DERIVED, D4) --}}
        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                <p class="text-theme-xs uppercase text-gray-400">EMV ({{ app(\App\Shared\Support\TenantCurrency::class)->code() }})</p>
                <div class="mt-1">
                    @if ($totals->total_emv !== null)
                        <span class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ number_format((float) $totals->total_emv, 2) }}</span>
                        <x-metric.tier-badge tier="ESTIMATED" />
                    @else
                        <x-states.unavailable reason="No EMV yet — EMV needs rates set up under Settings → EMV." />
                    @endif
                </div>
            </div>
            <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                <p class="text-theme-xs uppercase text-gray-400">CPE (spend / engagement)</p>
                <div class="mt-1">
                    <x-metric.value :metric="$cpe" :decimals="2" :reason="$cpeReason" />
                </div>
            </div>
            <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                <p class="text-theme-xs uppercase text-gray-400">CPM (spend / views ÷ 1000)</p>
                <div class="mt-1">
                    <x-metric.value :metric="$cpm" :decimals="2" :reason="$cpmReason" />
                </div>
            </div>
        </div>

        {{-- AC-M1-011 convention: every EMV figure discloses the model + rates USED. --}}
        <div class="mt-2">
            {{-- Deep-review M4: producing models, not the active config. --}}
            <x-metric.emv-disclosure :configurations="$emvConfigurations" />
        </div>

        {{-- Per creator — display-time regrouping of ROLLUP-SeedingByShipment rows --}}
        <h4 class="mt-6 text-sm font-semibold text-gray-700 dark:text-gray-200">Per creator</h4>
        @if ($creatorRows->isEmpty())
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No shipment results yet.</p>
        @else
            <div class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[720px]">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-800">
                            <x-table.th>Creator</x-table.th>
                            <x-table.th>Shipments</x-table.th>
                            <x-table.th>Posted</x-table.th>
                            <x-table.th>Content</x-table.th>
                            <x-table.th>Views</x-table.th>
                            <x-table.th>Engagement</x-table.th>
                            <x-table.th>EMV</x-table.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($creatorRows as $row)
                            <tr wire:key="seeding-results-creator-{{ $row->creator_id }}">
                                <td class="px-5 py-3 text-sm font-medium text-gray-800 dark:text-white/90">
                                    @if (isset($creatorNames[$row->creator_id]))
                                        <a href="{{ route('crm.creators.show', $row->creator_id) }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $creatorNames[$row->creator_id] }}</a>
                                    @else
                                        #{{ $row->creator_id }}
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ number_format($row->shipments) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ number_format($row->posted) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ number_format($row->content_count) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->views !== null)
                                        {{ number_format($row->views) }} <x-metric.tier-badge tier="PUBLIC" />
                                    @else
                                        <x-states.unavailable reason="No observed views for this creator — never shown as zero." />
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->engagement !== null)
                                        {{ number_format($row->engagement) }} <x-metric.tier-badge tier="DERIVED" />
                                    @else
                                        <x-states.unavailable reason="No observed engagement for this creator — never shown as zero." />
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->emv !== null)
                                        {{ number_format($row->emv, 2) }} <x-metric.tier-badge tier="ESTIMATED" />
                                    @else
                                        <x-states.unavailable reason="No EMV yet — EMV needs rates set up under Settings → EMV." />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Per shipment (AC-M3-018): what was sent, did they post, when, how did it perform --}}
        <h4 class="mt-6 text-sm font-semibold text-gray-700 dark:text-gray-200">Per shipment</h4>
        @if ($shipmentRows->isEmpty())
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No shipment results yet.</p>
        @else
            <div class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[860px]">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-800">
                            <x-table.th>Creator</x-table.th>
                            <x-table.th>Product</x-table.th>
                            <x-table.th>Shipped</x-table.th>
                            <x-table.th>Posted</x-table.th>
                            <x-table.th>Days to post</x-table.th>
                            <x-table.th>Content</x-table.th>
                            <x-table.th>Views</x-table.th>
                            <x-table.th>Engagement</x-table.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($shipmentRows as $row)
                            <tr wire:key="seeding-results-shipment-{{ $row->shipment_id }}">
                                <td class="px-5 py-3 text-sm font-medium text-gray-800 dark:text-white/90">
                                    @if (isset($creatorNames[$row->creator_id]))
                                        <a href="{{ route('crm.creators.show', $row->creator_id) }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $creatorNames[$row->creator_id] }}</a>
                                    @else
                                        #{{ $row->creator_id }}
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    {{ $productNames[$row->product_id] ?? '—' }}
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $row->shipped_date }}</td>
                                <td class="px-5 py-3">
                                    @if ((int) $row->posted === 1)
                                        <x-ui.badge color="success">Posted</x-ui.badge>
                                    @else
                                        <x-ui.badge color="light">Not posted</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->days_to_post !== null)
                                        {{ number_format((float) $row->days_to_post, 1) }} <x-metric.tier-badge tier="DERIVED" />
                                    @else
                                        <span class="text-sm text-gray-400">&mdash;</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ number_format((int) $row->content_count) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->views !== null)
                                        {{ number_format((float) $row->views) }} <x-metric.tier-badge tier="PUBLIC" />
                                    @else
                                        <x-states.unavailable reason="No observed views for this shipment — never shown as zero." />
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($shipmentEngagements[(int) $row->shipment_id] !== null)
                                        {{ number_format($shipmentEngagements[(int) $row->shipment_id]) }} <x-metric.tier-badge tier="DERIVED" />
                                    @else
                                        <x-states.unavailable reason="No observed engagement for this shipment — never shown as zero." />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <x-data-freshness :at="$rollupsRefreshedAt" label="Data refreshed" class="mt-4 block" />
    </div>
</div>
