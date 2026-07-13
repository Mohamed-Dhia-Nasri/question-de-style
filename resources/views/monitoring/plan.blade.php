<x-layouts.app title="Monitoring plan">
    <x-page-header title="Monitoring plan"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Monitoring' => route('monitoring.index'), 'Plan' => null]" />

    <livewire:monitoring.monitoring-plan-settings />
</x-layouts.app>
