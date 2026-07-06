<x-layouts.app title="Monitoring & Reporting">
    <x-page-header title="Monitoring & Reporting" :breadcrumbs="['Dashboard' => route('dashboard'), 'Monitoring' => null]">
        <x-slot:actions>
            <a href="{{ route('monitoring.creators.index') }}"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                Creators
            </a>
            <a href="{{ route('monitoring.review') }}"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                Review queue
            </a>
            <a href="{{ route('monitoring.exports.index') }}"
                class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">
                Exports
            </a>
        </x-slot:actions>
    </x-page-header>

    <livewire:monitoring.monitoring-overview />
</x-layouts.app>
