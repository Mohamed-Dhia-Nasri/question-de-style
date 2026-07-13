<div>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
        <a href="{{ route('monitoring.creators.index') }}"
            class="rounded-2xl border border-gray-200 bg-white p-5 transition hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-800">
            <p class="text-sm text-gray-500 dark:text-gray-400">Tracked creators</p>
            <p class="mt-2 text-title-sm font-bold text-gray-800 dark:text-white/90">{{ number_format($rosterCount) }}</p>
            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Active roster subjects (ADR-0011)</p>
        </a>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Mentions (30d)</p>
            <p class="mt-2 text-title-sm font-bold text-gray-800 dark:text-white/90">{{ number_format($mentions30d) }}</p>
            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Detected across the roster, last 30 days</p>
        </div>

        <a href="{{ route('crm.index') }}"
            class="rounded-2xl border border-gray-200 bg-white p-5 transition hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-800">
            <p class="text-sm text-gray-500 dark:text-gray-400">Active campaigns</p>
            <p class="mt-2 text-title-sm font-bold text-gray-800 dark:text-white/90">{{ number_format($activeCampaigns) }}</p>
            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                @if ($activeSeedingRuns > 0)
                    + {{ number_format($activeSeedingRuns) }} seeding {{ Str::plural('run', $activeSeedingRuns) }} in flight
                @else
                    No seeding runs in flight
                @endif
            </p>
        </a>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Estimated reach (30d)</p>
            <div class="mt-2">
                @if ($estimatedReach30d !== null)
                    <span class="text-title-sm font-bold text-gray-800 dark:text-white/90">{{ number_format((float) $estimatedReach30d) }}</span>
                    <x-metric.tier-badge tier="ESTIMATED" />
                @else
                    <x-states.unavailable
                        reason="No estimated-reach figures in the rollups for this period; CONFIRMED unique reach is deferred in v1 (DEF-003) and never fabricated." />
                @endif
            </div>
            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                @if ($rollupsRefreshedAt !== null)
                    Rollups refreshed {{ $rollupsRefreshedAt->diffForHumans() }}
                @else
                    Rollups not refreshed yet
                @endif
            </p>
        </div>
    </div>

    <div class="mt-6 rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @if ($recentContent->isEmpty())
            <x-states.empty title="No activity yet">
                No content has been ingested yet. Data starts flowing once monitoring is enabled
                (QDS_INGESTION_ENABLED) and the first cycle polls the roster.
            </x-states.empty>
        @else
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Latest content</h3>
            </div>
            <ul class="divide-y divide-gray-200 dark:divide-gray-800">
                @foreach ($recentContent as $item)
                    <li>
                        <a href="{{ route('monitoring.content.show', $item) }}"
                            class="flex items-center gap-4 px-5 py-3 transition hover:bg-gray-50 dark:hover:bg-white/5">
                            <span class="shrink-0 rounded-full bg-gray-100 px-2.5 py-0.5 text-theme-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                {{ $item->platform->value }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm text-gray-800 dark:text-white/90">
                                    {{ $item->caption !== null && $item->caption !== '' ? Str::limit($item->caption, 90) : 'Untitled '.Str::lower(str_replace('_', ' ', $item->content_type->value)) }}
                                </span>
                                <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ $item->platformAccount?->creator?->display_name ?? $item->platformAccount?->handle ?? 'Unknown creator' }}
                                    · {{ $item->published_at?->diffForHumans() }}
                                </span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
