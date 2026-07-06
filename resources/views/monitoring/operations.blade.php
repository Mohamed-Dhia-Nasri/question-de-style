<x-layouts.app title="Operations">
    <x-page-header title="Operations & data health" :breadcrumbs="['Dashboard' => route('dashboard'), 'Monitoring' => route('monitoring.index'), 'Operations' => null]" />
    <livewire:monitoring.operations-dashboard />
</x-layouts.app>
