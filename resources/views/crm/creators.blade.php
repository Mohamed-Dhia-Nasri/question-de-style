<x-layouts.app title="Creators">
    <x-page-header title="Creators" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM' => route('crm.index'),
        'Creators' => null,
    ]" />

    @livewire('crm.creators-index')

    @livewire('crm.creator-csv-import')
</x-layouts.app>
