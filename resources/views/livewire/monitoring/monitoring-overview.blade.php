<div>
    {{-- Headline totals — a compact, subordinate strip above the roster. --}}
    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Last 90 days</h3>
        <x-data-freshness :at="$dataUpdatedAt" label="Data updated" never="not pulled yet" />
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            ['label' => 'Total posts', 'value' => $totalPosts],
            ['label' => 'Total views', 'value' => $totalViews],
            ['label' => 'Total likes', 'value' => $totalLikes],
            ['label' => 'Total comments', 'value' => $totalComments],
        ] as $kpi)
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $kpi['label'] }}</p>
                {{-- Never a fabricated zero: a metric with no observed data
                     reads "—" (DP-001), while a true count stays numeric. --}}
                <p class="mt-1 text-xl font-semibold text-gray-800 dark:text-white/90">
                    {{ $kpi['value'] !== null ? number_format((float) $kpi['value']) : '—' }}
                </p>
            </div>
        @endforeach
    </div>

    {{-- Roster of monitored creators — the primary content of this page. --}}
    <div class="mt-8">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Monitored creators</h2>
                <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                    {{-- $creators->total() is the count AFTER the platform/search/
                         seeding filters, so the label must say "matching" when a
                         filter narrows it — calling a filtered subset "on the
                         roster" would misreport the true roster size. --}}
                    @if (trim($search) !== '' || $platform !== '' || $activeSeedingOnly)
                        {{ number_format($creators->total()) }} {{ \Illuminate\Support\Str::plural('creator', $creators->total()) }} matching
                    @else
                        {{ number_format($creators->total()) }} {{ \Illuminate\Support\Str::plural('creator', $creators->total()) }} on the roster
                    @endif
                </p>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <div class="w-full sm:w-56">
                    <x-form.label for="overview-search">Search</x-form.label>
                    <x-form.input id="overview-search" type="search" placeholder="Creator name…"
                        wire:model.live.debounce.400ms="search" />
                </div>
                <div class="w-full sm:w-40">
                    <x-form.label for="overview-platform">Platform</x-form.label>
                    <x-form.select id="overview-platform" wire:model.live="platform">
                        <option value="">All platforms</option>
                        @foreach ($platforms as $p)
                            <option value="{{ $p->value }}">{{ $p->label() }}</option>
                        @endforeach
                    </x-form.select>
                </div>
                <div class="mb-1.5">
                    <x-form.toggle id="overview-seeding" wire:model.live="activeSeedingOnly" label="Active seeding only" />
                </div>
            </div>
        </div>

        {{-- How fresh is this? Creators refresh on a schedule, not continuously.
             The intervals are read from the effective monitoring plan
             (CadenceSettings), so this line always matches actual behaviour. --}}
        @php
            $everyLabel = function (int $hours): string {
                if ($hours >= 48) {
                    return rtrim(rtrim(number_format(round($hours / 24, 1), 1), '0'), '.').' days';
                }

                return $hours.' hours';
            };
        @endphp
        <p class="mb-5 flex items-start gap-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"
                aria-hidden="true" class="mt-0.5 shrink-0">
                <path d="M12 16v-4m0-4h.01M22 12a10 10 0 11-20 0 10 10 0 0120 0z"
                    stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <span>Fresh data arrives on a schedule — creators in a running campaign or seeding refresh about every {{ $everyLabel($refreshCampaignHours) }}, others about every {{ $everyLabel($refreshBaselineHours) }}. Use “Run monitoring now” on a creator’s page to pull immediately.</span>
        </p>

        @if ($activeSeedingOnly && $seedingSetEmpty)
            <div class="mb-4 rounded-2xl border border-gray-200 bg-white p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300">
                No creators are currently in an active seeding.
            </div>
        @endif

        {{-- Gate on the FULL result total, not the current page. paginate()
             does not clamp an out-of-range ?page= value, so a stale page number
             leaves the current page empty while the roster is not — isEmpty()
             would then wrongly show the "add creators" empty state over a
             non-empty roster. total() === 0 means genuinely nothing matches; an
             out-of-range page instead falls through to the grid + pagination
             links below, so the operator can navigate back. --}}
        @if ($creators->total() === 0)
            <x-states.empty title="No monitored creators match">
                @if (trim($search) !== '' || $platform !== '' || $activeSeedingOnly)
                    Try clearing the search or filters above.
                @else
                    Add creators to the roster to start monitoring them (REQ-M1-001).
                @endif
            </x-states.empty>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3"
                wire:loading.class="pointer-events-none opacity-50">
                @foreach ($creators as $creator)
                    <a href="{{ route('monitoring.creators.show', $creator) }}" wire:key="creator-{{ $creator->id }}"
                        class="group flex flex-col rounded-2xl border border-gray-200 bg-white p-5 transition hover:border-brand-300 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
                        <div class="flex items-start gap-3">
                            <x-ui.avatar :name="$creator->display_name" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">
                                    {{ $creator->display_name }}
                                </p>
                                @if ($creator->relationship_status && $creator->relationship_status !== \App\Shared\Enums\RelationshipStatus::None)
                                    <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $creator->relationship_status->label() }}
                                    </p>
                                @endif
                            </div>
                            @if (isset($seededLookup[$creator->id]))
                                <x-ui.badge color="success" class="shrink-0">In active seeding</x-ui.badge>
                            @endif
                        </div>

                        <div class="mt-4 space-y-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                            @forelse ($creator->platformAccounts as $account)
                                <div class="flex items-center justify-between gap-2">
                                    <x-ui.badge color="light">{{ $account->platform->label() }}</x-ui.badge>
                                    <x-metric.value :metric="$account->follower_count"
                                        reason="No follower count observed yet." class="text-sm" />
                                </div>
                            @empty
                                <p class="text-theme-xs text-gray-400">No linked accounts yet.</p>
                            @endforelse
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $creators->links() }}
            </div>
        @endif
    </div>
</div>
