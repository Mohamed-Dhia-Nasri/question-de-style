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

        @livewire('crm.campaign-results', ['campaign' => $campaign])

        @livewire('crm.campaign-creators', ['campaign' => $campaign])

        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-800">
                <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Seeding runs</h3>
            </div>
            @if ($campaign->seedingCampaigns->isEmpty())
                <x-states.empty title="No seeding runs yet">
                    Seeding runs under this campaign send its brand’s products to creators.
                    <x-slot:action>
                        <a href="{{ route('crm.seeding.index') }}" class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Go to Seeding runs →</a>
                    </x-slot:action>
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

        @livewire('crm.documents-panel', ['campaign' => $campaign])
        @livewire('crm.tasks-panel', ['campaign' => $campaign])
    </div>
</x-layouts.app>
