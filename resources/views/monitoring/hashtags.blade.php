<x-layouts.app title="Hashtag lists">
    <x-page-header title="Hashtag lists"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Monitoring' => route('monitoring.index'), 'Hashtags' => null]" />

    @livewire('monitoring.hashtag-lists-index')
</x-layouts.app>
