<x-layouts.app title="Products">
    <x-page-header title="Products" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM' => route('crm.index'),
        'Products' => null,
    ]" />

    @livewire('crm.products-index')
</x-layouts.app>
