<x-layouts.app title="Report exports">
    <x-page-header title="Report exports" :breadcrumbs="['Dashboard' => route('dashboard'), 'Monitoring' => route('monitoring.index'), 'Exports' => null]" />
    <livewire:monitoring.exports-index />
</x-layouts.app>
