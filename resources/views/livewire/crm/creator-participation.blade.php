<div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-800">
        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Participation</h3>
        <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
            Campaigns, seeding runs, and shipments this creator is involved in.
        </p>
    </div>

    @if ($campaigns->isEmpty() && $seedingRuns->isEmpty() && $shipments->isEmpty())
        <x-states.empty title="Not involved in anything yet">
            This creator isn’t on any campaign or seeding run. Add them from a campaign or seeding run page.
        </x-states.empty>
    @else
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            {{-- Campaigns --}}
            <div class="px-6 py-5">
                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Campaigns</h4>
                @if ($campaigns->isEmpty())
                    <p class="mt-2 text-sm text-gray-400">Not on any campaign.</p>
                @else
                    <div class="mt-3 space-y-2">
                        @foreach ($campaigns as $campaign)
                            <div class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-800"
                                wire:key="campaign-{{ $campaign->id }}">
                                <div>
                                    <a href="{{ route('crm.campaigns.show', $campaign) }}"
                                        class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                        {{ $campaign->name }}
                                    </a>
                                    <p class="text-theme-xs text-gray-400">{{ $campaign->brand->name }}</p>
                                </div>
                                <x-ui.badge color="primary">{{ $campaign->status->label() }}</x-ui.badge>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Seeding runs --}}
            <div class="px-6 py-5">
                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Seeding runs</h4>
                @if ($seedingRuns->isEmpty())
                    <p class="mt-2 text-sm text-gray-400">Not on any seeding run.</p>
                @else
                    <div class="mt-3 space-y-2">
                        @foreach ($seedingRuns as $run)
                            <div class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-800"
                                wire:key="run-{{ $run->id }}">
                                <div>
                                    <a href="{{ route('crm.seeding.show', $run) }}"
                                        class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                        {{ $run->name }}
                                    </a>
                                    <p class="text-theme-xs text-gray-400">{{ $run->brand->name }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <x-ui.badge color="light">{{ $run->seeding_type->label() }}</x-ui.badge>
                                    <x-ui.badge color="primary">{{ $run->status->label() }}</x-ui.badge>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Shipments --}}
            <div class="px-6 py-5">
                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Shipments</h4>
                @if ($shipments->isEmpty())
                    <p class="mt-2 text-sm text-gray-400">No shipments.</p>
                @else
                    <div class="mt-3 space-y-2">
                        @foreach ($shipments->take(10) as $shipment)
                            <div class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-800"
                                wire:key="shipment-{{ $shipment->id }}">
                                <div>
                                    <span class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $shipment->product->name }}</span>
                                    <p class="text-theme-xs text-gray-400">
                                        <a href="{{ route('crm.seeding.show', $shipment->seedingCampaign) }}"
                                            class="text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                            {{ $shipment->seedingCampaign->name }}
                                        </a>
                                    </p>
                                </div>
                                <x-ui.badge color="primary">{{ $shipment->status->label() }}</x-ui.badge>
                            </div>
                        @endforeach
                    </div>
                    @if ($shipments->count() > 10)
                        <p class="mt-2 text-theme-xs text-gray-400">
                            …and {{ $shipments->count() - 10 }} more on the seeding run pages.
                        </p>
                    @endif
                @endif
            </div>
        </div>
    @endif
</div>
