<x-layouts.app title="EMV settings">
    <x-page-header title="EMV settings"
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Settings' => route('settings.emv'), 'EMV' => null]" />

    @livewire('monitoring.emv-settings')
</x-layouts.app>
