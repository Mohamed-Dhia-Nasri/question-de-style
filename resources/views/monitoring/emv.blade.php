<x-layouts.app title="EMV configurations">
    <x-page-header title="EMV configurations"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Monitoring' => route('monitoring.index'), 'EMV' => null]" />

    @livewire('monitoring.emv-configurations-index')
</x-layouts.app>
