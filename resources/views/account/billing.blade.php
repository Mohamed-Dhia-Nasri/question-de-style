<x-layouts.app title="Billing">
    <x-page-header title="Billing" :breadcrumbs="['Dashboard' => route('dashboard'), 'Account' => route('account.index'), 'Billing' => null]" />

    @livewire('billing.billing-manage')
</x-layouts.app>
