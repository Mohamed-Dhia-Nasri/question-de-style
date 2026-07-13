<x-layouts.app title="Results & Reporting">
    <x-page-header title="Results & Reporting" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM & Seeding' => route('crm.index'),
        'Results' => null,
    ]" />

    @livewire('crm.seeding-results-dashboard')
</x-layouts.app>
