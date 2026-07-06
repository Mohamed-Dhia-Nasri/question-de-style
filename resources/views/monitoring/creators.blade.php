<x-layouts.app title="Monitored creators">
    <x-page-header title="Monitored creators" :breadcrumbs="['Dashboard' => route('dashboard'), 'Monitoring' => route('monitoring.index'), 'Creators' => null]" />
    <livewire:monitoring.creators-index />
</x-layouts.app>
