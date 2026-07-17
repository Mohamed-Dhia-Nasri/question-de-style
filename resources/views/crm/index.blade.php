<x-layouts.app title="CRM">
    <x-page-header title="CRM" :breadcrumbs="['Dashboard' => route('dashboard'), 'CRM' => null]">
        <x-slot:actions>
            @can('create', \App\Modules\CRM\Models\Campaign::class)
                <a href="{{ route('crm.campaigns.create') }}">
                    <x-ui.button size="sm">New campaign</x-ui.button>
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @livewire('crm.overview')
</x-layouts.app>
