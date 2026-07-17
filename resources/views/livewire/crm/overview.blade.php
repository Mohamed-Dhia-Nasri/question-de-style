<div class="space-y-6">
    @if (! $setupComplete)
        <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Get set up</h3>
            <ul class="mt-3 space-y-2">
                @foreach ($checklist as $step)
                    <li class="flex items-center gap-2.5 text-sm {{ $step['done'] ? 'text-gray-400 line-through' : 'text-gray-700 dark:text-gray-300' }}">
                        <span class="flex h-5 w-5 items-center justify-center rounded-full {{ $step['done'] ? 'bg-success-50 text-success-600 dark:bg-success-500/10' : 'bg-gray-100 text-gray-400 dark:bg-white/5' }}">
                            @if ($step['done'])✓@else○@endif
                        </span>
                        @if ($step['done'])
                            <span>{{ $step['label'] }}</span>
                        @else
                            <span>
                                @if ($step['can'])
                                    <a href="{{ $step['url'] }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $step['label'] }}</a>
                                @else
                                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $step['label'] }}</span>
                                @endif
                                <span class="text-gray-400">— {{ $step['hint'] }}</span>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @else
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <x-ui.badge color="success">✓ You’re all set up</x-ui.badge>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Needs attention</h3>

            @if ($attention['overdueTasks'] === 0 && $attention['emptyRuns'] === 0 && $attention['awaitedShipments'] === 0)
                <x-states.empty title="Nothing needs your attention right now">
                    Check back after your next campaign moves.
                </x-states.empty>
            @else
                <ul class="mt-3 space-y-2">
                    @if ($attention['overdueTasks'] > 0)
                        <li class="text-sm">
                            <a href="{{ route('crm.tasks.index') }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $attention['overdueTasks'] }} {{ \Illuminate\Support\Str::plural('task', $attention['overdueTasks']) }} {{ $attention['overdueTasks'] === 1 ? 'is' : 'are' }} overdue</a>
                        </li>
                    @endif
                    @if ($attention['emptyRuns'] > 0)
                        <li class="text-sm">
                            <a href="{{ route('crm.seeding.index') }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $attention['emptyRuns'] }} {{ \Illuminate\Support\Str::plural('seeding run', $attention['emptyRuns']) }} {{ $attention['emptyRuns'] === 1 ? 'has' : 'have' }} no creators yet</a>
                        </li>
                    @endif
                    @if ($attention['awaitedShipments'] > 0)
                        <li class="text-sm">
                            <a href="{{ route('crm.seeding.index') }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $attention['awaitedShipments'] }} {{ \Illuminate\Support\Str::plural('shipment', $attention['awaitedShipments']) }} {{ $attention['awaitedShipments'] === 1 ? 'has' : 'have' }} been on the road for more than a week</a>
                        </li>
                    @endif
                </ul>
            @endif
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Active work</h3>

            @if ($activeCampaigns->isEmpty() && $activeRuns->isEmpty())
                <x-states.empty title="No active campaigns or seeding runs yet">
                    Everything you set live shows up here.
                </x-states.empty>
            @else
                <ul class="mt-3 space-y-3">
                    @foreach ($activeCampaigns as $campaign)
                        <li class="flex items-center justify-between gap-2 text-sm">
                            <a href="{{ route('crm.campaigns.show', $campaign) }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                {{ $campaign->name }}
                            </a>
                            <span class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                                <x-ui.badge color="primary">{{ $campaign->status->label() }}</x-ui.badge>
                                <span>{{ $campaign->seeding_campaigns_count }} {{ \Illuminate\Support\Str::plural('run', $campaign->seeding_campaigns_count) }}</span>
                            </span>
                        </li>
                    @endforeach
                    @foreach ($activeRuns as $run)
                        <li class="flex items-center justify-between gap-2 text-sm">
                            <a href="{{ route('crm.seeding.show', $run) }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                {{ $run->name }}
                            </a>
                            <span class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                                <x-ui.badge color="primary">{{ $run->status->label() }}</x-ui.badge>
                                <span>{{ $run->shipments_count }} {{ \Illuminate\Support\Str::plural('shipment', $run->shipments_count) }} · {{ $run->creators_count }} {{ \Illuminate\Support\Str::plural('creator', $run->creators_count) }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap gap-3">
        @can('create', \App\Modules\CRM\Models\Client::class)
            <a href="{{ route('crm.clients.index') }}?create=1">
                <x-ui.button variant="outline" size="sm">New client</x-ui.button>
            </a>
        @endcan
        @can('create', \App\Modules\CRM\Models\Brand::class)
            <a href="{{ route('crm.brands.index') }}?create=1">
                <x-ui.button variant="outline" size="sm">New brand</x-ui.button>
            </a>
        @endcan
        @can('create', \App\Modules\CRM\Models\Creator::class)
            <a href="{{ route('crm.creators.index') }}?create=1">
                <x-ui.button variant="outline" size="sm">New creator</x-ui.button>
            </a>
        @endcan
        @can('create', \App\Modules\CRM\Models\SeedingCampaign::class)
            <a href="{{ route('crm.seeding.index') }}?create=1">
                <x-ui.button variant="outline" size="sm">New seeding run</x-ui.button>
            </a>
        @endcan
    </div>

    @can('users.manage')
        <p class="text-sm text-gray-500 dark:text-gray-400">
            User administration is available under Admin → Users.
        </p>
    @endcan
</div>
