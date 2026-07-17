<x-layouts.app :title="$brand->name">
    <x-page-header :title="$brand->name" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM' => route('crm.index'),
        'Clients & Brands' => route('crm.clients.index'),
        $brand->name => null,
    ]" />

    <div class="space-y-6">
        <x-crm.context-header :client="$brand->client" :brand="$brand" :brand-link="false">
            @if ($brand->sector)
                <span>Sector: <x-ui.badge color="light">{{ $brand->sector->label() }}</x-ui.badge></span>
            @endif
        </x-crm.context-header>

        @livewire('crm.brand-detail', ['brand' => $brand])
    </div>
</x-layouts.app>
