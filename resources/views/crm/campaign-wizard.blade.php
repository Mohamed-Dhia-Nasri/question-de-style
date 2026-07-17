<x-layouts.app title="New campaign">
    <x-page-header title="New campaign" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM' => route('crm.index'),
        'Campaigns' => route('crm.campaigns.index'),
        'New campaign' => null,
    ]" />

    @livewire('crm.campaign-wizard')
</x-layouts.app>
