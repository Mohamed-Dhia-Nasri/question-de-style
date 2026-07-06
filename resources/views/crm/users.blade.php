<x-layouts.app title="Users">
    <x-page-header title="Users" :breadcrumbs="['Dashboard' => route('dashboard'), 'Users' => null]" />

    @livewire('crm.users-index')
</x-layouts.app>
