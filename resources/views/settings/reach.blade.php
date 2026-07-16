<x-layouts.app title="Reach settings">
    <x-page-header title="Reach settings"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Settings' => route('settings.reach'), 'Reach' => null]" />

    @livewire('monitoring.reach-settings')
</x-layouts.app>
