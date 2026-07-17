<x-layouts.app title="CRM">
    <x-page-header title="CRM" :breadcrumbs="['Dashboard' => route('dashboard'), 'CRM' => null]" />

    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <a href="{{ route('crm.clients.index') }}"
            class="group rounded-2xl border border-gray-200 bg-white p-6 transition-colors hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
            <h3 class="text-base font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">
                Clients
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                The companies your agency works for. Every brand belongs to a client.
            </p>
        </a>

        <a href="{{ route('crm.brands.index') }}"
            class="group rounded-2xl border border-gray-200 bg-white p-6 transition-colors hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
            <h3 class="text-base font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">
                Brands
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                A client’s brands — campaigns, seeding runs, and products all attach to a brand.
            </p>
        </a>

        <a href="{{ route('crm.products.index') }}"
            class="group rounded-2xl border border-gray-200 bg-white p-6 transition-colors hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
            <h3 class="text-base font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">
                Products
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                The products you send to creators, grouped by brand.
            </p>
        </a>

        <a href="{{ route('crm.creators.index') }}"
            class="group rounded-2xl border border-gray-200 bg-white p-6 transition-colors hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
            <h3 class="text-base font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">
                Creators
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Your influencer database — profiles, platform accounts, contact details, brand
                preferences, and conversation history.
            </p>
        </a>

        <a href="{{ route('crm.campaigns.index') }}"
            class="group rounded-2xl border border-gray-200 bg-white p-6 transition-colors hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
            <h3 class="text-base font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">
                Campaigns
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Plan and track campaigns for a brand, with the participating creators.
            </p>
        </a>

        <a href="{{ route('crm.seeding.index') }}"
            class="group rounded-2xl border border-gray-200 bg-white p-6 transition-colors hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
            <h3 class="text-base font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">
                Seeding runs
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Send products to creators and track every shipment and the content that came
                back.
            </p>
        </a>

        <a href="{{ route('crm.results') }}"
            class="group rounded-2xl border border-gray-200 bg-white p-6 transition-colors hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
            <h3 class="text-base font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">
                Results & Reporting
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Campaign and seeding results across creators — views, engagement, estimated
                reach, and Earned Media Value (EMV).
            </p>
        </a>

        @php
            // Open-task count for the Tasks card — the statuses a deadline
            // still matters for (AC-M3-017; TasksIndex::openStatuses()).
            $openTaskCount = \App\Modules\CRM\Models\Task::query()
                ->whereIn('status', \App\Modules\CRM\Livewire\Tasks\TasksIndex::openStatuses())
                ->count();
        @endphp
        <a href="{{ route('crm.tasks.index') }}"
            class="group rounded-2xl border border-gray-200 bg-white p-6 transition-colors hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
            <h3 class="flex items-center gap-2 text-base font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">
                Tasks
                <x-ui.badge color="primary" size="sm">{{ $openTaskCount }} open</x-ui.badge>
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Deadlines and follow-ups, linked to creators and campaigns. You get a reminder
                before a deadline.
            </p>
        </a>
    </div>

    @can('users.manage')
        <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">
            User administration is available under Admin → Users.
        </p>
    @endcan
</x-layouts.app>
