<x-layouts.app title="Review queue">
    <x-page-header title="Review queue"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Monitoring' => route('monitoring.index'), 'Review queue' => null]" />

    @livewire('monitoring.review-queue-index')
</x-layouts.app>
