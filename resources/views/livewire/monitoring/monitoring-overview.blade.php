<div>
    {{-- Server-side filters (validated in the component; state kept small) --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <x-form.label for="overview-platform">Platform</x-form.label>
            <x-form.select id="overview-platform" wire:model.live="platform">
                <option value="">All platforms</option>
                @foreach ($platforms as $p)
                    <option value="{{ $p->value }}">{{ $p->value }}</option>
                @endforeach
            </x-form.select>
        </div>
        <div>
            <x-form.label for="overview-brand">Brand</x-form.label>
            <x-form.select id="overview-brand" wire:model.live="brandId">
                <option value="0">All brands</option>
                @foreach ($brands as $brand)
                    <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                @endforeach
            </x-form.select>
        </div>
        <div>
            <x-form.label for="overview-from">From</x-form.label>
            <x-form.input id="overview-from" type="date" wire:model.live="from" />
        </div>
        <div>
            <x-form.label for="overview-to">To</x-form.label>
            <x-form.input id="overview-to" type="date" wire:model.live="to" />
        </div>
    </div>

    {{-- KPI cards --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Monitored creators (roster)</p>
            <p class="mt-2 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format($rosterCount) }}</p>
            <a href="{{ route('monitoring.creators.index') }}" class="mt-1 inline-block text-theme-xs text-brand-500 hover:underline">View roster</a>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">New content in period</p>
            <p class="mt-2 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format($newContent) }}</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Active stories</p>
            <p class="mt-2 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format($activeStories) }}</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Pending reviews (DP-004)</p>
            <p class="mt-2 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format($pendingReviews) }}</p>
            <a href="{{ route('monitoring.review') }}" class="mt-1 inline-block text-theme-xs text-brand-500 hover:underline">Open review queue</a>
        </div>
    </div>

    {{-- Rollup-backed totals — every figure tier-labelled (DP-001) --}}
    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Views (period)</p>
            <div class="mt-2">
                @if ($creatorTotals->views_sum !== null)
                    <span class="text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format((float) $creatorTotals->views_sum) }}</span>
                    <x-metric.tier-badge tier="PUBLIC" />
                @else
                    <x-states.unavailable reason="No observed views in the selected period — never shown as zero." />
                @endif
            </div>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Engagement (period)</p>
            <div class="mt-2">
                @if ($creatorTotals->engagement_sum !== null)
                    <span class="text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format((float) $creatorTotals->engagement_sum) }}</span>
                    <x-metric.tier-badge tier="PUBLIC" />
                @else
                    <x-states.unavailable reason="No observed engagement in the selected period — never shown as zero." />
                @endif
            </div>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Estimated reach (period)</p>
            <div class="mt-2">
                @if ($mentionTotals->total_estimated_reach !== null)
                    <span class="text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format((float) $mentionTotals->total_estimated_reach) }}</span>
                    <x-metric.tier-badge tier="ESTIMATED" />
                @else
                    <x-states.unavailable reason="No estimated reach in the rollups for this period yet (REQ-M1-006)." />
                @endif
            </div>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">EMV (period)</p>
            <div class="mt-2">
                @if ($mentionTotals->total_emv !== null)
                    <span class="text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format((float) $mentionTotals->total_emv, 2) }}</span>
                    <x-metric.tier-badge tier="ESTIMATED" />
                @else
                    <x-states.unavailable reason="EMV requires an active, user-managed EMV configuration (REQ-M1-011) and calculated results." />
                @endif
            </div>
        </div>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        {{-- Mentions by ENUM-MentionType --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-800 dark:text-white/90">Mentions by type</h3>
            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                PAID/SEEDED only with a proving record; organic is never asserted as fact.
            </p>
            @php $mentionMax = max(1, (int) $mentionsByType->max()); @endphp
            <div class="mt-4 space-y-3">
                @foreach (['PAID', 'SEEDED', 'LIKELY_ORGANIC', 'UNKNOWN'] as $type)
                    @php $count = (int) ($mentionsByType[$type] ?? 0); @endphp
                    <div class="flex items-center gap-3">
                        <span class="w-32 shrink-0 text-theme-xs font-medium text-gray-600 dark:text-gray-300">{{ $type }}</span>
                        <div class="h-2.5 flex-1 rounded-full bg-gray-100 dark:bg-white/5">
                            <div class="h-2.5 rounded-full bg-brand-500" style="width: {{ (int) round($count / $mentionMax * 100) }}%"></div>
                        </div>
                        <span class="w-10 text-right text-theme-xs text-gray-600 dark:text-gray-300">{{ $count }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Ingestion / provider health summary --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold text-gray-800 dark:text-white/90">Ingestion & provider health</h3>
                @can(\App\Shared\Authorization\PermissionsCatalog::OPERATIONS_VIEW)
                    <a href="{{ route('monitoring.operations') }}" class="text-theme-xs text-brand-500 hover:underline">Operations</a>
                @endcan
            </div>
            <div class="mt-4 space-y-2">
                @if ($failingProviders->isEmpty() && $staleProviders->isEmpty())
                    <p class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <x-ui.badge color="success">healthy</x-ui.badge> No provider failures or stale-data warnings.
                    </p>
                @endif
                @foreach ($failingProviders as $source => $state)
                    <p class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <x-ui.badge color="error">failing</x-ui.badge>
                        {{ $source }} — {{ $state['consecutive_failures'] }} consecutive failure(s)
                    </p>
                @endforeach
                @foreach ($staleProviders as $source => $state)
                    <p class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <x-ui.badge color="warning">stale</x-ui.badge>
                        {{ $source }} — last success {{ $state['last_success_at'] ?? 'unknown' }}
                    </p>
                @endforeach
            </div>
            <p class="mt-4 text-theme-xs text-gray-400 dark:text-gray-500">
                Rollups refreshed: {{ $rollupsRefreshedAt?->diffForHumans() ?? 'never' }}
            </p>
        </div>
    </div>

    {{-- Deferred capabilities — mandatory unavailable states, never blank --}}
    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-800 dark:text-white/90">Open-web listening</h3>
            <div class="mt-3">
                <x-states.unavailable reason="Open-web brand/keyword/hashtag listening from non-roster creators is deferred (DEF-006, ADR-0011)." />
            </div>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-800 dark:text-white/90">Comment & audience-reaction analysis</h3>
            <div class="mt-3">
                <x-states.unavailable reason="Comment collection and audience-reaction analysis are deferred (DEF-005, ADR-0009)." />
            </div>
        </div>
    </div>
</div>
