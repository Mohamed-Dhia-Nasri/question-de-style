<x-layouts.app title="Users">
    <x-page-header title="Users" :breadcrumbs="['Dashboard' => route('dashboard'), 'Users' => null]" />

    @livewire('crm.users-index')

    {{-- Team invitations + seat usage (ADR-0021) — same users.manage gate. --}}
    <div class="mt-6">
        @livewire('billing.team-invitations')
    </div>
</x-layouts.app>
