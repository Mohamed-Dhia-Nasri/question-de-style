<x-layouts.app title="Seeding runs">
    <x-page-header title="Seeding runs" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM' => route('crm.index'),
        'Seeding runs' => null,
    ]" />

    @livewire('crm.seeding-campaigns-index')
</x-layouts.app>
