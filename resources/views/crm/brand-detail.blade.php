<x-layouts.app :title="$brand->name">
    <x-page-header :title="$brand->name" :breadcrumbs="[
        'Dashboard' => route('dashboard'),
        'CRM' => route('crm.index'),
        'Clients & Brands' => route('crm.clients.index'),
        $brand->name => null,
    ]" />

    <div class="space-y-6">
        <x-crm.context-header :client="$brand->client" :brand="$brand" :brand-link="false">
            @if ($brand->sector)
                <span>Sector: <x-ui.badge color="light">{{ $brand->sector->label() }}</x-ui.badge></span>
            @endif
        </x-crm.context-header>

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
            <a href="{{ route('crm.products.index') }}" class="rounded-2xl border border-gray-200 bg-white p-6 hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-sm text-gray-500 dark:text-gray-400">Products</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $brand->products_count }}</p>
            </a>
            <a href="{{ route('crm.campaigns.index') }}" class="rounded-2xl border border-gray-200 bg-white p-6 hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-sm text-gray-500 dark:text-gray-400">Campaigns</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $brand->campaigns_count }}</p>
            </a>
            <a href="{{ route('crm.seeding.index') }}" class="rounded-2xl border border-gray-200 bg-white p-6 hover:border-brand-300 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-sm text-gray-500 dark:text-gray-400">Seeding runs</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $brand->seeding_campaigns_count }}</p>
            </a>
        </div>
    </div>
</x-layouts.app>
