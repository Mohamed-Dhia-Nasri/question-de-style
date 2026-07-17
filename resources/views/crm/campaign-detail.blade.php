<x-layouts.app :title="$campaign->name">
    <x-page-header :title="$campaign->name" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM' => route('crm.index'),
        'Campaigns' => route('crm.campaigns.index'),
        $campaign->name => null,
    ]" />

    <div class="space-y-6">
        <x-crm.context-header :client="$campaign->brand->client" :brand="$campaign->brand" :campaign="$campaign" :campaign-link="false" :status="$campaign->status">
            <span>Dates:
                {{ $campaign->start_at?->format('d.m.Y') ?? '—' }} – {{ $campaign->end_at?->format('d.m.Y') ?? '—' }}
            </span>
        </x-crm.context-header>

        <div x-data="{ tab: ['overview','creators','seeding','results','docs'].includes(window.location.hash.slice(1)) ? window.location.hash.slice(1) : 'overview' }"
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
                    Creators ({{ $campaign->creators_count }})
                </button>
                <button type="button" role="tab" :aria-selected="tab === 'seeding'" x-on:click="tab = 'seeding'"
                    :class="tab === 'seeding' ? 'border-brand-500 text-brand-500 dark:text-brand-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                    class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium">
                    Seeding runs ({{ $campaign->seeding_campaigns_count }})
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
                        ['done' => $campaign->start_at !== null && $campaign->end_at !== null, 'label' => 'Set the campaign dates', 'hint' => 'Edit the campaign on the Campaigns page.'],
                        ['done' => $campaign->creators_count > 0, 'label' => 'Add participating creators', 'go' => 'creators'],
                        ['done' => $campaign->seeding_campaigns_count > 0, 'label' => 'Create a seeding run (optional)', 'go' => 'seeding'],
                    ];
                    $openSteps = collect($setupSteps)->where('done', false);
                @endphp

                @if (in_array($campaign->status, [\App\Shared\Enums\CampaignStatus::Draft, \App\Shared\Enums\CampaignStatus::Planned], true) && $openSteps->isNotEmpty())
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
                        <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $campaign->creators_count }}</p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Seeding runs</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $campaign->seeding_campaigns_count }}</p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Status</p>
                        <p class="mt-1"><x-ui.badge color="primary">{{ $campaign->status->label() }}</x-ui.badge></p>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $campaign->status->description() }}</p>
                    </div>
                </div>
            </div>

            <div x-show="tab === 'creators'" x-cloak>
                @livewire('crm.campaign-creators', ['campaign' => $campaign])
            </div>

            <div x-show="tab === 'seeding'" x-cloak>
                <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-800">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Seeding runs</h3>
                        @livewire('crm.seeding-run-create', ['campaign' => $campaign])
                    </div>
                    @if ($campaign->seedingCampaigns->isEmpty())
                        <x-states.empty title="No seeding runs yet">
                            Seeding runs under this campaign send its brand’s products to creators. Create the first one right here.
                        </x-states.empty>
                    @else
                        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($campaign->seedingCampaigns as $seeding)
                                <li class="flex items-center justify-between px-6 py-3">
                                    <a href="{{ route('crm.seeding.show', $seeding) }}"
                                        class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                        {{ $seeding->name }}
                                    </a>
                                    <span class="flex items-center gap-2">
                                        <x-ui.badge color="light">{{ $seeding->seeding_type->label() }}</x-ui.badge>
                                        <x-ui.badge color="primary">{{ $seeding->status->label() }}</x-ui.badge>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            <div x-show="tab === 'results'" x-cloak>
                @livewire('crm.campaign-results', ['campaign' => $campaign])
            </div>

            <div x-show="tab === 'docs'" x-cloak class="space-y-6">
                @livewire('crm.documents-panel', ['campaign' => $campaign])
                @livewire('crm.tasks-panel', ['campaign' => $campaign])
            </div>
        </div>
    </div>
</x-layouts.app>
