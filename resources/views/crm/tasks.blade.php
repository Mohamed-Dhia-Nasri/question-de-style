<x-layouts.app title="Tasks">
    <x-page-header title="Tasks" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM & Seeding' => route('crm.index'),
        'Tasks' => null,
    ]" />

    @livewire('crm.tasks-index')
</x-layouts.app>
