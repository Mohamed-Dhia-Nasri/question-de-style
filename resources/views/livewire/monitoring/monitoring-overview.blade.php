<div>
    {{-- Server-side filters (validated in the component; state kept small) --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
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
            <x-form.input id="overview-from" type="date" wire:model.blur="from" />
        </div>
        <div>
            <x-form.label for="overview-to">To</x-form.label>
            <x-form.input id="overview-to" type="date" wire:model.blur="to" />
        </div>
        <div>
            <x-form.label for="overview-seeding">Seeding</x-form.label>
            <div class="mt-2.5">
                <x-form.toggle id="overview-seeding" wire:model.live="activeSeedingOnly" label="Active seeding only" />
            </div>
        </div>
    </div>

    @if ($activeSeedingOnly && $seedingSetEmpty)
        <div class="mb-4 rounded-2xl border border-gray-200 bg-white p-4 text-sm text-gray-600 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300">
            No creators are currently in an active seeding.
        </div>
    @endif

    {{-- Plain-English date scope: with no From/To the figures below cover all data. --}}
    <p class="mb-4 text-theme-xs text-gray-500 dark:text-gray-400">
        Showing: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $rangeLabel }}</span>
    </p>

    {{-- KPI cards --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Monitored creators (roster)</p>
            <p class="mt-2 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format($rosterCount) }}</p>
            <a href="{{ route('monitoring.creators.index') }}" class="mt-1 inline-block text-theme-xs text-brand-500 hover:underline">View roster</a>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">New content</p>
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
            <p class="text-sm text-gray-500 dark:text-gray-400">Views</p>
            <div class="mt-2">
                @if ($creatorTotals->views_sum !== null)
                    <span class="text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format((float) $creatorTotals->views_sum) }}</span>
                    <x-metric.tier-badge tier="PUBLIC" />
                @else
                    <x-states.unavailable reason="No observed views for the selected dates — never shown as zero." />
                @endif
            </div>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Engagement</p>
            <div class="mt-2">
                @if ($creatorTotals->engagement_sum !== null)
                    <span class="text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format((float) $creatorTotals->engagement_sum) }}</span>
                    <x-metric.tier-badge tier="PUBLIC" />
                @else
                    <x-states.unavailable reason="No observed engagement for the selected dates — never shown as zero." />
                @endif
            </div>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Estimated reach</p>
            <div class="mt-2">
                @if ($activeSeedingOnly)
                    <x-states.unavailable reason="Aggregated by brand — not available for the seeding filter." />
                @elseif ($mentionTotals->total_estimated_reach !== null)
                    <span class="text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format((float) $mentionTotals->total_estimated_reach) }}</span>
                    <x-metric.tier-badge tier="ESTIMATED" />
                @else
                    <x-states.unavailable reason="No estimated reach for the selected dates yet (REQ-M1-006)." />
                @endif
            </div>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">EMV</p>
            <div class="mt-2">
                @if ($activeSeedingOnly)
                    <x-states.unavailable reason="Aggregated by brand — not available for the seeding filter." />
                @elseif ($mentionTotals->total_emv !== null)
                    <span class="text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format((float) $mentionTotals->total_emv, 2) }}@if ($mentionTotals->total_emv_currency) <span class="text-base font-normal text-gray-500 dark:text-gray-400">{{ $mentionTotals->total_emv_currency }}</span>@endif</span>
                    <x-metric.tier-badge tier="ESTIMATED" />
                @else
                    <x-states.unavailable reason="EMV requires an active, user-managed EMV configuration (REQ-M1-011) and calculated results." />
                @endif
            </div>
        </div>
    </div>

    {{--
        Hidden for now (user request 2026-07-18):
          • "Mentions by type" — the mentions-by-ENUM-MentionType breakdown.
          • "Data collection status" — the plain-English provider-health list
            (rendered from $providerRows via ProviderHealthPresenter).
        The component still computes $mentionsByType and $providerRows, so
        restoring is a view-only change: re-add the lg:grid-cols-2 row here.

        Also hidden earlier: the "Open-web listening" (DEF-006, ADR-0011) and
        "Comment & audience-reaction analysis" (DEF-005, ADR-0009) panels, which
        only showed an "unavailable" state for features not yet built.
    --}}
</div>
