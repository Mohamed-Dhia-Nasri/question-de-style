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

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
                    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Creators</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $seedingCampaign->creators_count }}</p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Shipments</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $seedingCampaign->shipments_count }}</p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Status</p>
                        <p class="mt-1"><x-ui.badge color="primary">{{ $seedingCampaign->status->label() }}</x-ui.badge></p>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $seedingCampaign->status->description() }}</p>
                    </div>
                </div>
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
            </div>
        </div>
    </div>
</x-layouts.app>
