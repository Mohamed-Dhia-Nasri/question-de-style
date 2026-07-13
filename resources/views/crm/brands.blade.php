<x-layouts.app title="Brands">
    <x-page-header title="Brands" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM & Seeding' => route('crm.index'),
        'Brands' => null,
    ]" />

    @livewire('crm.brands-index')
</x-layouts.app>
