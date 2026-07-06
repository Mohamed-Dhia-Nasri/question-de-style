<div>
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-64">
            <x-form.label for="creators-search">Search</x-form.label>
            <x-form.input id="creators-search" type="search" placeholder="Creator name…"
                wire:model.live.debounce.400ms="search" />
        </div>
        <div class="w-full sm:w-48">
            <x-form.label for="creators-platform">Platform</x-form.label>
            <x-form.select id="creators-platform" wire:model.live="platform">
                <option value="">All platforms</option>
                @foreach ($platforms as $p)
                    <option value="{{ $p->value }}">{{ $p->value }}</option>
                @endforeach
            </x-form.select>
        </div>
        <div class="w-full sm:w-32">
            <x-form.label for="creators-per-page">Per page</x-form.label>
            <x-form.select id="creators-per-page" wire:model.live="perPage">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
            </x-form.select>
        </div>
    </div>

    <x-table.container>
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
            <thead>
                <tr>
                    <x-table.th field="display_name" :sort-field="$sortField" :sort-direction="$sortDirection">Creator</x-table.th>
                    <x-table.th>Accounts</x-table.th>
                    <x-table.th>Followers</x-table.th>
                    <x-table.th>Follower growth</x-table.th>
                    <x-table.th>Avg views</x-table.th>
                    <x-table.th>Engagement rate</x-table.th>
                    <x-table.th>Posting frequency</x-table.th>
                    <x-table.th>Last post</x-table.th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($creators as $creator)
                    @php $stat = $stats->get($creator->id); @endphp
                    <tr wire:key="creator-{{ $creator->id }}">
                        <td class="px-5 py-3">
                            <a href="{{ route('monitoring.creators.show', $creator) }}"
                                class="font-medium text-brand-500 hover:underline">{{ $creator->display_name }}</a>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex flex-wrap gap-1">
                                @forelse ($creator->platformAccounts as $account)
                                    <x-ui.badge color="light">{{ $account->platform->value }}</x-ui.badge>
                                @empty
                                    <span class="text-theme-xs text-gray-400">No linked accounts</span>
                                @endforelse
                            </div>
                        </td>
                        <td class="px-5 py-3 text-sm">
                            @if ($stat?->followers !== null)
                                {{ number_format((float) $stat->followers) }} <x-metric.tier-badge tier="PUBLIC" />
                            @else
                                <x-states.unavailable reason="No account snapshot in the latest period." />
                            @endif
                        </td>
                        <td class="px-5 py-3 text-sm">
                            @if ($stat?->follower_growth !== null)
                                {{ number_format((float) $stat->follower_growth) }} <x-metric.tier-badge tier="DERIVED" />
                            @else
                                <x-states.unavailable reason="Growth needs at least two snapshots in the period (ADR-0003)." />
                            @endif
                        </td>
                        <td class="px-5 py-3 text-sm">
                            @if ($stat?->avg_views !== null)
                                {{ number_format((float) $stat->avg_views) }} <x-metric.tier-badge tier="DERIVED" />
                            @else
                                <x-states.unavailable reason="No observed content views in the latest period." />
                            @endif
                        </td>
                        <td class="px-5 py-3 text-sm">
                            @if ($stat?->engagement_rate !== null)
                                {{ number_format((float) $stat->engagement_rate, 4) }} <x-metric.tier-badge tier="DERIVED" />
                            @else
                                <x-states.unavailable reason="Engagement rate needs observed engagement and followers." />
                            @endif
                        </td>
                        <td class="px-5 py-3 text-sm">
                            <x-states.unavailable reason="No canonical posting-frequency formula is documented (flagged decision gap)." />
                        </td>
                        <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                            @if ($stat?->last_post_at !== null)
                                {{ \Illuminate\Support\Carbon::parse($stat->last_post_at)->diffForHumans() }}
                            @else
                                <x-states.unavailable reason="No content observed in the latest period." />
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <x-states.empty title="No monitored creators match">
                                Add creators to the roster as MonitoredSubjects of type CREATOR (REQ-M1-001).
                            </x-states.empty>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <x-slot:footer>
            {{ $creators->links() }}
        </x-slot:footer>
    </x-table.container>
</div>
