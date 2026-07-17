<x-layouts.app :title="$creator->display_name">
    <x-page-header :title="$creator->display_name" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM' => route('crm.index'),
        'Creators' => route('crm.creators.index'),
        $creator->display_name => null,
    ]" />

    <div class="space-y-6">
        @livewire('crm.creator-profile', ['creator' => $creator])
        @livewire('crm.creator-participation', ['creator' => $creator])
        @livewire('crm.creator-platform-accounts', ['creator' => $creator])
        @livewire('crm.creator-geography', ['creator' => $creator])
        @livewire('crm.creator-contacts', ['creator' => $creator])
        @livewire('crm.creator-brand-preferences', ['creator' => $creator])
        @livewire('crm.creator-communication-log', ['creator' => $creator])
        @livewire('crm.documents-panel', ['creator' => $creator])
        @livewire('crm.tasks-panel', ['creator' => $creator])
    </div>
</x-layouts.app>
