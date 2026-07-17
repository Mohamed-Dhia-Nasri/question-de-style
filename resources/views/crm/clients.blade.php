<x-layouts.app title="Clients & Brands">
    <x-page-header title="Clients & Brands" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM' => route('crm.index'),
        'Clients & Brands' => null,
    ]" />

    @livewire('crm.clients-index')
</x-layouts.app>
