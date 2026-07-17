<x-layouts.app title="Monitoring settings">
    <x-page-header title="Monitoring settings"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Settings' => route('settings.monitoring'), 'Monitoring' => null]" />

    @livewire('monitoring.monitoring-settings')
</x-layouts.app>
