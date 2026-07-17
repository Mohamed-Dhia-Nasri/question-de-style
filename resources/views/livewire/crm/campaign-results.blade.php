<div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-800">
        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Results</h3>
        <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
            Results for this campaign.
        </p>
        <x-metric.tier-legend class="mt-1" />
    </div>

    <div class="p-6">
        {{-- AC-M3-014: content count + PUBLIC views/likes/comments, DERIVED engagement, tiered reach --}}
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                <p class="text-theme-xs uppercase text-gray-400">Mentions</p>
                {{-- A count of zero IS a real measurement — rendered, not "unavailable". --}}
                <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{{ number_format((int) ($totals->mention_count ?? 0)) }}</p>
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
                        <x-states.unavailable reason="No observed views for this campaign — never shown as zero." />
                    @endif
                </div>
            </div>
            <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                <p class="text-theme-xs uppercase text-gray-400">Likes / comments</p>
                <div class="mt-1">
                    @if ($totals->total_likes !== null || $totals->total_comments !== null)
                        <span class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ ($totals->total_likes !== null ? number_format((float) $totals->total_likes) : '—').' / '.($totals->total_comments !== null ? number_format((float) $totals->total_comments) : '—') }}
                        </span>
                        <x-metric.tier-badge tier="PUBLIC" />
                    @else
                        <x-states.unavailable reason="No observed likes or comments for this campaign — never shown as zero." />
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
                        <x-states.unavailable reason="No observed engagement components for this campaign — never shown as zero." />
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
                    <x-metric.value :metric="$campaign->spend" :decimals="2"
                        reason="No spend entered for this campaign yet — add it when editing the campaign." />
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

        {{-- Per child seeding run — totals from ROLLUP-SeedingByCreatorCampaign --}}
        <h4 class="mt-6 text-sm font-semibold text-gray-700 dark:text-gray-200">Seeding runs</h4>
        @if ($campaign->seedingCampaigns->isEmpty())
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No seeding runs under this campaign yet.</p>
        @else
            <div class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[760px]">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-800">
                            <x-table.th>Run</x-table.th>
                            <x-table.th>Shipments</x-table.th>
                            <x-table.th>Posted</x-table.th>
                            <x-table.th>Content</x-table.th>
                            <x-table.th>Views</x-table.th>
                            <x-table.th>Engagement</x-table.th>
                            <x-table.th>EMV</x-table.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($campaign->seedingCampaigns as $run)
                            @php $run_totals = $runTotals[$run->id]; @endphp
                            <tr wire:key="campaign-results-run-{{ $run->id }}">
                                <td class="px-5 py-3 text-sm font-medium">
                                    <a href="{{ route('crm.seeding.show', $run) }}"
                                        class="text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $run->name }}</a>
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ number_format((int) ($run_totals->shipments ?? 0)) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ number_format((int) ($run_totals->posted_count ?? 0)) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ number_format((int) ($run_totals->content_count ?? 0)) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($run_totals->total_views !== null)
                                        {{ number_format((float) $run_totals->total_views) }} <x-metric.tier-badge tier="PUBLIC" />
                                    @else
                                        <x-states.unavailable reason="No observed views for this run — never shown as zero." />
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($run_totals->total_engagement !== null)
                                        {{ number_format((float) $run_totals->total_engagement) }} <x-metric.tier-badge tier="DERIVED" />
                                    @else
                                        <x-states.unavailable reason="No observed engagement for this run — never shown as zero." />
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($run_totals->total_emv !== null)
                                        {{ number_format((float) $run_totals->total_emv, 2) }} <x-metric.tier-badge tier="ESTIMATED" />
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

        <p class="mt-4 text-theme-xs text-gray-400 dark:text-gray-500">
            Data refreshed {{ $rollupsRefreshedAt?->diffForHumans() ?? 'never' }}.
        </p>
    </div>
</div>
