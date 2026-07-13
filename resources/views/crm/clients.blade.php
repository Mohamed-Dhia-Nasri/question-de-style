<x-layouts.app title="Clients">
    <x-page-header title="Clients" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM & Seeding' => route('crm.index'),
        'Clients' => null,
    ]" />

    @livewire('crm.clients-index')
</x-layouts.app>
