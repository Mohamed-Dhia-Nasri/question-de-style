<x-layouts.app title="Account">
    <x-page-header title="Account" :breadcrumbs="['Dashboard' => route('dashboard'), 'Account' => null]" />

    @livewire('billing.account-overview')
</x-layouts.app>
