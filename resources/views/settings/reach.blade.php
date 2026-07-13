<x-layouts.app title="Reach formula">
    <x-page-header title="Reach formula"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Settings' => route('settings.reach'), 'Reach' => null]" />

    @livewire('monitoring.reach-formula-index')
</x-layouts.app>
