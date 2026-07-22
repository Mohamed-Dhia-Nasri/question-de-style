<x-layouts.app :title="$seedingCampaign->name">
    <x-page-header :title="$seedingCampaign->name" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM' => route('crm.index'),
        'Seeding runs' => route('crm.seeding.index'),
        $seedingCampaign->name => null,
    ]" />

    <div class="space-y-6">
        <x-crm.context-header :client="$seedingCampaign->brand->client" :brand="$seedingCampaign->brand" :campaign="$seedingCampaign->campaign" :seeding-run="$seedingCampaign" :status="$seedingCampaign->status">
            <span>Seeding type: <x-ui.badge color="light">{{ $seedingCampaign->seeding_type->label() }}</x-ui.badge></span>
            <span>Product:
                @if ($seedingCampaign->product)
                    <a href="{{ route('crm.products.index', ['q' => $seedingCampaign->product->name]) }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $seedingCampaign->product->name }}</a>
                @else
                    <span class="font-medium text-gray-800 dark:text-white/90">—</span>
                @endif
            </span>
        </x-crm.context-header>

        <div x-data="{ tab: ['overview','creators','shipments','results','docs'].includes(window.location.hash.slice(1)) ? window.location.hash.slice(1) : 'overview' }"
            x-init="$watch('tab', value => history.replaceState(null, '', '#' + value))">
            <div class="mb-4 flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-800" role="tablist">
                <button type="button" role="tab" :aria-selected="tab === 'overview'" x-on:click="tab = 'overview'"
                    :class="tab === 'overview' ? 'border-brand-500 text-brand-500 dark:text-brand-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                    class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium">
                    Overview
                </button>
                <button type="button" role="tab" :aria-selected="tab === 'creators'" x-on:click="tab = 'creators'"
                    :class="tab === 'creators' ? 'border-brand-500 text-brand-500 dark:text-brand-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                    class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium">
                    Creators ({{ $seedingCampaign->creators_count }})
                </button>
                <button type="button" role="tab" :aria-selected="tab === 'shipments'" x-on:click="tab = 'shipments'"
                    :class="tab === 'shipments' ? 'border-brand-500 text-brand-500 dark:text-brand-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                    class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium">
                    Shipments ({{ $seedingCampaign->shipments_count }})
                </button>
                <button type="button" role="tab" :aria-selected="tab === 'results'" x-on:click="tab = 'results'"
                    :class="tab === 'results' ? 'border-brand-500 text-brand-500 dark:text-brand-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                    class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium">
                    Results
                </button>
                <button type="button" role="tab" :aria-selected="tab === 'docs'" x-on:click="tab = 'docs'"
                    :class="tab === 'docs' ? 'border-brand-500 text-brand-500 dark:text-brand-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                    class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium">
                    Docs & Tasks
                </button>
            </div>

            <div x-show="tab === 'overview'" x-cloak class="space-y-6">
                @php
                    $setupSteps = [
                        ['done' => $seedingCampaign->product_id !== null, 'label' => 'Choose a product', 'hint' => 'Edit the run on the Seeding runs page.'],
                        ['done' => $seedingCampaign->creators_count > 0, 'label' => 'Add the creators who receive products', 'go' => 'creators'],
                        ['done' => $seedingCampaign->shipments_count > 0, 'label' => 'Record the first shipment', 'go' => 'shipments'],
                    ];
                    $openSteps = collect($setupSteps)->where('done', false);
                @endphp

                @if (in_array($seedingCampaign->status, [\App\Shared\Enums\SeedingCampaignStatus::Draft, \App\Shared\Enums\SeedingCampaignStatus::Planned], true) && $openSteps->isNotEmpty())
                    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Finish setting up</h3>
                        <ul class="mt-3 space-y-2">
                            @foreach ($setupSteps as $step)
                                <li class="flex items-center gap-2.5 text-sm {{ $step['done'] ? 'text-gray-400 line-through' : 'text-gray-700 dark:text-gray-300' }}">
                                    <span class="flex h-5 w-5 items-center justify-center rounded-full {{ $step['done'] ? 'bg-success-50 text-success-600 dark:bg-success-500/10' : 'bg-gray-100 text-gray-400 dark:bg-white/5' }}">
                                        @if ($step['done'])✓@else○@endif
                                    </span>
                                    @if (! $step['done'] && isset($step['go']))
                                        <button type="button" x-on:click="tab = '{{ $step['go'] }}'" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $step['label'] }} →</button>
                                    @else
                                        <span>{{ $step['label'] }}@if (! $step['done'] && isset($step['hint'])) <span class="text-gray-400">— {{ $step['hint'] }}</span>@endif</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Run progress — the seeding pipeline (Creators → Shipped →
                     Delivered → Posted), the run's core state at a glance.
                     Replaces the dense one-line summary + redundant cards. --}}
                @if ($seedingCampaign->creators_count > 0 || $seedingCampaign->shipments_count > 0)
                    @php
                        // Clamp to 100: a not-required creator who posts anyway can
                        // push posted above the expected denominator.
                        $pct = fn (int $n, int $d): int => $d > 0 ? min(100, (int) round($n / $d * 100)) : 0;
                        $shipmentsTotal = $seedingCampaign->shipments_count;
                        $expectedPosts = $seedingCampaign->expected_posts_count;
                        $stages = [
                            ['label' => 'Creators', 'value' => (string) $seedingCampaign->creators_count, 'caption' => 'on the roster', 'pct' => null],
                            ['label' => 'Shipped', 'value' => $seedingCampaign->shipped_count.' of '.$shipmentsTotal, 'pct' => $pct($seedingCampaign->shipped_count, $shipmentsTotal)],
                            ['label' => 'Delivered', 'value' => $seedingCampaign->delivered_count.' of '.$shipmentsTotal, 'pct' => $pct($seedingCampaign->delivered_count, $shipmentsTotal)],
                            // When no post is required the denominator is 0, so show a
                            // plain count ("1"), never a nonsensical "1 of 0".
                            ['label' => 'Posted', 'value' => $expectedPosts > 0 ? $seedingCampaign->posted_count.' of '.$expectedPosts : (string) $seedingCampaign->posted_count, 'pct' => $expectedPosts > 0 ? $pct($seedingCampaign->posted_count, $expectedPosts) : null, 'caption' => $expectedPosts === 0 ? 'no posts required' : null],
                        ];
                    @endphp
                    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Run progress</h3>
                            <span class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                                Status <x-ui.badge color="primary">{{ $seedingCampaign->status->label() }}</x-ui.badge>
                            </span>
                        </div>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $seedingCampaign->status->description() }}</p>

                        <div class="mt-5 grid grid-cols-2 gap-x-6 gap-y-5 sm:grid-cols-4">
                            @foreach ($stages as $stage)
                                <div>
                                    <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">{{ $stage['label'] }}</p>
                                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-800 dark:text-white/90">{{ $stage['value'] }}</p>
                                    @if (! is_null($stage['pct']))
                                        <div class="mt-2 h-1.5 rounded-full bg-gray-100 dark:bg-white/5">
                                            <div class="h-1.5 rounded-full bg-brand-500" style="width: {{ $stage['pct'] }}%"></div>
                                        </div>
                                    @elseif (! empty($stage['caption']))
                                        <p class="mt-2 text-theme-xs text-gray-400">{{ $stage['caption'] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-4 text-theme-xs text-gray-500 dark:text-gray-400">Posted rises as each creator’s post is matched to this run.</p>
                    </div>
                @endif

                {{-- Results teaser — a peek at outcomes with a jump to the full
                     Results tab. Same approved rollup the Results tab reads. --}}
                @if ($seedingCampaign->shipments_count > 0)
                    @php
                        $hasResults = ($resultsTotals->content_count ?? 0) > 0
                            || $resultsTotals->total_views !== null
                            || $resultsTotals->total_engagement !== null;
                    @endphp
                    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Results so far</h3>
                            <button type="button" x-on:click="tab = 'results'" class="shrink-0 text-theme-sm font-medium text-brand-500 hover:underline dark:text-brand-400">View full results →</button>
                        </div>

                        @if ($hasResults)
                            <div class="mt-4 grid grid-cols-3 gap-4">
                                <div>
                                    <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Posts</p>
                                    <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $resultsTotals->content_count !== null ? number_format($resultsTotals->content_count) : '—' }}</p>
                                </div>
                                <div>
                                    <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Views</p>
                                    <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">
                                        {{ $resultsTotals->total_views !== null ? number_format((float) $resultsTotals->total_views) : '—' }}
                                        @if ($resultsTotals->total_views !== null)<x-metric.tier-badge tier="PUBLIC" />@endif
                                    </p>
                                </div>
                                <div>
                                    <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Engagement</p>
                                    <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">
                                        {{ $resultsTotals->total_engagement !== null ? number_format((float) $resultsTotals->total_engagement) : '—' }}
                                        @if ($resultsTotals->total_engagement !== null)<x-metric.tier-badge tier="PUBLIC" />@endif
                                    </p>
                                </div>
                            </div>
                        @else
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">No results yet — numbers appear here once creators post and their content is matched to this run.</p>
                        @endif
                    </div>
                @endif
            </div>

            <div x-show="tab === 'creators'" x-cloak>
                @livewire('crm.seeding-creators', ['seedingCampaign' => $seedingCampaign])
            </div>

            <div x-show="tab === 'shipments'" x-cloak>
                @livewire('crm.seeding-shipments', ['seedingCampaign' => $seedingCampaign])
            </div>

            <div x-show="tab === 'results'" x-cloak>
                @livewire('crm.seeding-results', ['seedingCampaign' => $seedingCampaign])
            </div>

            <div x-show="tab === 'docs'" x-cloak class="space-y-6">
                @livewire('crm.documents-panel', ['seedingCampaign' => $seedingCampaign])
                @livewire('crm.tasks-panel', ['seedingCampaign' => $seedingCampaign])
            </div>
        </div>
    </div>
</x-layouts.app>
