<x-layouts.app title="Campaigns">
    <x-page-header title="Campaigns" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM' => route('crm.index'),
        'Campaigns' => null,
    ]" />

    @livewire('crm.campaigns-index')
</x-layouts.app>
