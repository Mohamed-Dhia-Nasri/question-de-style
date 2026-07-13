<x-layouts.app title="Seeding campaigns">
    <x-page-header title="Seeding campaigns" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM & Seeding' => route('crm.index'),
        'Seeding' => null,
    ]" />

    @livewire('crm.seeding-campaigns-index')
</x-layouts.app>
