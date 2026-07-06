<x-layouts.app title="Dashboard">
    <x-page-header title="Dashboard" />

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Tracked creators</p>
            <p class="mt-2 text-title-sm font-bold text-gray-800 dark:text-white/90">&mdash;</p>
            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Available when Monitoring ships (P1)</p>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Mentions (30d)</p>
            <p class="mt-2 text-title-sm font-bold text-gray-800 dark:text-white/90">&mdash;</p>
            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Available when Monitoring ships (P1)</p>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Active campaigns</p>
            <p class="mt-2 text-title-sm font-bold text-gray-800 dark:text-white/90">&mdash;</p>
            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Available when CRM &amp; Seeding ships (P3)</p>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Confirmed unique reach (30d)</p>
            <p class="mt-2">
                <x-states.unavailable
                    reason="Confirmed unique reach is deferred in v1 (DEF-003) — labelled estimates ship with Module 1" />
            </p>
            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Estimated reach ships with Monitoring (P1)</p>
        </div>
    </div>

    <div class="mt-6 rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <x-states.empty title="No activity yet">
            The platform foundation (P0) is in place — authentication, roles, and the module areas.
            Data starts flowing when the Monitoring module (P1) connects the ingestion pipeline.
        </x-states.empty>
    </div>
</x-layouts.app>
