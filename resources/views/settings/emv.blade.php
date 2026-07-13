<x-layouts.app title="EMV configurations">
    <x-page-header title="EMV configurations"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Settings' => route('settings.emv'), 'EMV' => null]" />

    @livewire('monitoring.emv-configurations-index')
</x-layouts.app>
