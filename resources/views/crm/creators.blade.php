<x-layouts.app title="Creators">
    <x-page-header title="Creators" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM & Seeding' => route('crm.index'),
        'Creators' => null,
    ]" />

    @livewire('crm.creators-index')
</x-layouts.app>
