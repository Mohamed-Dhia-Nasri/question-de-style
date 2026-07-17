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
                <span class="font-medium text-gray-800 dark:text-white/90">{{ $seedingCampaign->product?->name ?? '—' }}</span>
            </span>
        </x-crm.context-header>

        @livewire('crm.seeding-results', ['seedingCampaign' => $seedingCampaign])
        @livewire('crm.seeding-creators', ['seedingCampaign' => $seedingCampaign])
        @livewire('crm.seeding-shipments', ['seedingCampaign' => $seedingCampaign])
        @livewire('crm.documents-panel', ['seedingCampaign' => $seedingCampaign])
    </div>
</x-layouts.app>
