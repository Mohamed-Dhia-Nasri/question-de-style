<x-layouts.app title="CRM & Seeding">
    <x-page-header title="CRM & Seeding" :breadcrumbs="['Dashboard' => route('dashboard'), 'CRM & Seeding' => null]" />

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <x-states.empty title="CRM & Seeding ships in phase P3">
            The central influencer database, identity merge, contacts, campaigns, seeding,
            shipments, and results are delivered by Module 3.
            @can('users.manage')
                User administration is already available under Admin → Users.
            @endcan
        </x-states.empty>
    </div>
</x-layouts.app>
