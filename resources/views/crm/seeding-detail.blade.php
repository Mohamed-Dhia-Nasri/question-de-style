<x-layouts.app :title="$seedingCampaign->name">
    <x-page-header :title="$seedingCampaign->name" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM' => route('crm.index'),
        'Seeding runs' => route('crm.seeding.index'),
        $seedingCampaign->name => null,
    ]" />

    <div class="space-y-6">
        <div class="rounded-2xl border border-gray-200 bg-white px-6 py-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-wrap items-center gap-x-8 gap-y-2 text-sm text-gray-500 dark:text-gray-400">
                <span>Brand:
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $seedingCampaign->brand->name }}</span>
                </span>
                <span>Seeding type: <x-ui.badge color="light">{{ $seedingCampaign->seeding_type->label() }}</x-ui.badge></span>
                <span>Status: <x-ui.badge color="primary">{{ $seedingCampaign->status->label() }}</x-ui.badge></span>
                <span>Product:
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $seedingCampaign->product?->name ?? '—' }}</span>
                </span>
                <span>Parent campaign:
                    @if ($seedingCampaign->campaign)
                        <a href="{{ route('crm.campaigns.show', $seedingCampaign->campaign) }}"
                            class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                            {{ $seedingCampaign->campaign->name }}
                        </a>
                    @else
                        —
                    @endif
                </span>
            </div>
        </div>

        @livewire('crm.seeding-results', ['seedingCampaign' => $seedingCampaign])
        @livewire('crm.seeding-creators', ['seedingCampaign' => $seedingCampaign])
        @livewire('crm.seeding-shipments', ['seedingCampaign' => $seedingCampaign])
        @livewire('crm.documents-panel', ['seedingCampaign' => $seedingCampaign])
    </div>
</x-layouts.app>
