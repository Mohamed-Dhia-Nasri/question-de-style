{{-- resources/views/components/crm/context-header.blade.php
     The record's place in the hierarchy: Client › Brand › Campaign › Seeding run,
     each level a link except the current page. --}}
@props([
    'client' => null,          /** \App\Modules\CRM\Models\Client|null (linked to Clients & Brands) */
    'brand' => null,           /** \App\Modules\CRM\Models\Brand|null */
    'brandLink' => true,       /** false on the brand's own page */
    'campaign' => null,        /** \App\Modules\CRM\Models\Campaign|null */
    'campaignLink' => true,
    'seedingRun' => null,      /** \App\Modules\CRM\Models\SeedingCampaign|null */
    'status' => null,          /** enum with label() or null */
])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-gray-200 bg-white px-6 py-4 dark:border-gray-800 dark:bg-white/[0.03]']) }}>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-gray-500 dark:text-gray-400">
        <nav aria-label="Record hierarchy" class="flex flex-wrap items-center gap-x-1.5 gap-y-2">
            @if ($client)
                <a href="{{ route('crm.clients.index') }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $client->name }}</a>
            @endif
            @if ($brand)
                <span aria-hidden="true">›</span>
                @if ($brandLink)
                    <a href="{{ route('crm.brands.show', $brand) }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $brand->name }}</a>
                @else
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $brand->name }}</span>
                @endif
            @endif
            @if ($campaign)
                <span aria-hidden="true">›</span>
                @if ($campaignLink)
                    <a href="{{ route('crm.campaigns.show', $campaign) }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $campaign->name }}</a>
                @else
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $campaign->name }}</span>
                @endif
            @endif
            @if ($seedingRun)
                <span aria-hidden="true">›</span>
                <span class="font-medium text-gray-800 dark:text-white/90">{{ $seedingRun->name }}</span>
            @endif
        </nav>
        @if ($status)
            <x-ui.badge color="primary">{{ $status->label() }}</x-ui.badge>
        @endif
        {{ $slot }} {{-- extra facts: dates, seeding type, product — page-specific --}}
    </div>
</div>
