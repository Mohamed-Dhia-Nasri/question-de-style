<x-layouts.app title="CRM & Seeding">
    <x-page-header title="CRM & Seeding" :breadcrumbs="['Dashboard' => route('dashboard'), 'CRM & Seeding' => null]" />

    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <a href="{{ route('crm.creators.index') }}"
            class="group rounded-2xl border border-gray-200 bg-white p-6 transition-colors hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
            <h3 class="text-base font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">
                Creators
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                The central influencer database: creators, their platform accounts, contacts,
                brand preferences, and communication history. Identity is operator-managed
                in v1 — no merge feature (ADR-0014).
            </p>
        </a>

        <a href="{{ route('crm.campaigns.index') }}"
            class="group rounded-2xl border border-gray-200 bg-white p-6 transition-colors hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
            <h3 class="text-base font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">
                Campaigns
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Campaign management with participating creators — brand restrictions are
                enforced as hard filters on join.
            </p>
        </a>

        <a href="{{ route('crm.seeding.index') }}"
            class="group rounded-2xl border border-gray-200 bg-white p-6 transition-colors hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
            <h3 class="text-base font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">
                Seeding & Shipments
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                The four seeding variants, per-creator shipments, and content matched back to
                each shipment (automatic + manual).
            </p>
        </a>

        <a href="{{ route('crm.clients.index') }}"
            class="group rounded-2xl border border-gray-200 bg-white p-6 transition-colors hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
            <h3 class="text-base font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">
                Clients
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                The client organisations at the top of the client → brand → product hierarchy.
            </p>
        </a>

        <a href="{{ route('crm.brands.index') }}"
            class="group rounded-2xl border border-gray-200 bg-white p-6 transition-colors hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
            <h3 class="text-base font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">
                Brands
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Brands and their aliases — the primary aggregation dimension for mentions,
                campaigns, and seeding.
            </p>
        </a>

        <a href="{{ route('crm.products.index') }}"
            class="group rounded-2xl border border-gray-200 bg-white p-6 transition-colors hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
            <h3 class="text-base font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">
                Products
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Products and SKUs — the key that aggregates seeding results across creators.
            </p>
        </a>

        <a href="{{ route('crm.results') }}"
            class="group rounded-2xl border border-gray-200 bg-white p-6 transition-colors hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
            <h3 class="text-base font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">
                Results & Reporting
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Cross-influencer product totals with campaign and seeding results (EMV / CPE /
                CPM) — rollup-backed, every estimate tier-labelled.
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
                Deadlines and follow-ups, optionally linked to creators and campaigns — a
                nearing deadline fires a one-time in-app reminder (AC-M3-017).
            </p>
        </a>
    </div>

    @can('users.manage')
        <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">
            User administration is available under Admin → Users.
        </p>
    @endcan
</x-layouts.app>
