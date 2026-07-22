<div class="space-y-6">
    {{-- Setup checklist — shown only while incomplete; once every step exists it
         simply disappears (no wasted "all set up" card). --}}
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
    @endif

    {{-- Headline numbers — clickable, orient the operator at a glance. --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        @foreach ($kpis as $kpi)
            <a href="{{ $kpi['url'] }}"
                class="group rounded-xl border border-gray-200 bg-white p-4 transition hover:border-brand-300 hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $kpi['label'] }}</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800 group-hover:text-brand-500 dark:text-white/90 dark:group-hover:text-brand-400">{{ number_format($kpi['value']) }}</p>
            </a>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Needs attention — each alert names the specific record and links
             straight to it, so "which one?" is answered on the page. --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Needs attention</h3>

            @if ($overdueTasks->isEmpty() && $emptyRuns->isEmpty() && $awaitedShipments->isEmpty())
                <x-states.empty title="Nothing needs your attention right now">
                    You’re all caught up — new alerts show here as work moves.
                </x-states.empty>
            @else
                <div class="mt-4 space-y-4">
                    {{-- Overdue tasks (red = most urgent) --}}
                    @if ($overdueTasks->isNotEmpty())
                        <div>
                            <p class="flex items-center gap-2 text-theme-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <span class="h-2 w-2 rounded-full bg-error-500"></span> Overdue tasks
                            </p>
                            <ul class="mt-1.5 space-y-0.5">
                                @foreach ($overdueTasks->take(5) as $task)
                                    <li>
                                        <a href="{{ route('crm.tasks.index') }}"
                                            class="flex items-center justify-between gap-2 rounded-lg -mx-2 px-2 py-1 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">
                                            <span class="truncate">{{ $task->title }}</span>
                                            <span class="shrink-0 text-theme-xs text-gray-400">due {{ $task->due_at->diffForHumans() }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($overdueTasks->count() > 5)
                                <a href="{{ route('crm.tasks.index') }}" class="mt-1 inline-block text-theme-xs font-medium text-brand-500 hover:underline">See all tasks →</a>
                            @endif
                        </div>
                    @endif

                    {{-- Seeding runs with no creators (amber) --}}
                    @if ($emptyRuns->isNotEmpty())
                        <div>
                            <p class="flex items-center gap-2 text-theme-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <span class="h-2 w-2 rounded-full bg-warning-500"></span> Runs with no creators
                            </p>
                            <ul class="mt-1.5 space-y-0.5">
                                @foreach ($emptyRuns->take(5) as $run)
                                    <li>
                                        <a href="{{ route('crm.seeding.show', $run) }}#creators"
                                            class="flex items-center justify-between gap-2 rounded-lg -mx-2 px-2 py-1 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">
                                            <span class="truncate">{{ $run->name }}</span>
                                            <span class="shrink-0 text-theme-xs text-brand-500">Add creators →</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($emptyRuns->count() > 5)
                                <a href="{{ route('crm.seeding.index') }}" class="mt-1 inline-block text-theme-xs font-medium text-brand-500 hover:underline">See all runs →</a>
                            @endif
                        </div>
                    @endif

                    {{-- Shipments in transit over a week (amber) — names the parcel. --}}
                    @if ($awaitedShipments->isNotEmpty())
                        <div>
                            <p class="flex items-center gap-2 text-theme-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <span class="h-2 w-2 rounded-full bg-warning-500"></span> On the road over a week
                            </p>
                            <ul class="mt-1.5 space-y-0.5">
                                @foreach ($awaitedShipments->take(5) as $shipment)
                                    <li>
                                        <a href="{{ route('crm.seeding.show', $shipment->seedingCampaign) }}#shipments"
                                            class="flex items-center justify-between gap-2 rounded-lg -mx-2 px-2 py-1 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">
                                            <span class="truncate">{{ $shipment->creator?->display_name ?? 'Creator' }} · {{ $shipment->seedingCampaign?->name }}</span>
                                            <span class="shrink-0 text-theme-xs text-gray-400">shipped {{ $shipment->shipped_at->diffForHumans() }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($awaitedShipments->count() > 5)
                                <a href="{{ route('crm.seeding.index') }}" class="mt-1 inline-block text-theme-xs font-medium text-brand-500 hover:underline">See all runs →</a>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- Active work — what's live right now, grouped and self-explanatory. --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Active work</h3>

            @if ($activeCampaigns->isEmpty() && $activeRuns->isEmpty())
                <x-states.empty title="No active campaigns or seeding runs yet">
                    Everything you set live shows up here.
                </x-states.empty>
            @else
                @if ($activeCampaigns->isNotEmpty())
                    <div class="mt-4">
                        <div class="flex items-center justify-between">
                            <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Campaigns</p>
                            <a href="{{ route('crm.campaigns.index') }}" class="text-theme-xs font-medium text-brand-500 hover:underline">See all</a>
                        </div>
                        <ul class="mt-2 space-y-2">
                            @foreach ($activeCampaigns as $campaign)
                                <li class="flex items-center justify-between gap-2 text-sm">
                                    <a href="{{ route('crm.campaigns.show', $campaign) }}" class="truncate font-medium text-gray-800 hover:text-brand-500 dark:text-white/90 dark:hover:text-brand-400">{{ $campaign->name }}</a>
                                    <span class="flex shrink-0 items-center gap-2 text-gray-500 dark:text-gray-400">
                                        <x-ui.badge color="primary">{{ $campaign->status->label() }}</x-ui.badge>
                                        <span class="text-theme-xs">{{ $campaign->seeding_campaigns_count }} {{ \Illuminate\Support\Str::plural('run', $campaign->seeding_campaigns_count) }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($activeRuns->isNotEmpty())
                    <div class="mt-5">
                        <div class="flex items-center justify-between">
                            <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Seeding runs</p>
                            <a href="{{ route('crm.seeding.index') }}" class="text-theme-xs font-medium text-brand-500 hover:underline">See all</a>
                        </div>
                        <ul class="mt-2 space-y-3">
                            @foreach ($activeRuns as $run)
                                <li class="text-sm">
                                    <div class="flex items-center justify-between gap-2">
                                        <a href="{{ route('crm.seeding.show', $run) }}" class="truncate font-medium text-gray-800 hover:text-brand-500 dark:text-white/90 dark:hover:text-brand-400">{{ $run->name }}</a>
                                        <x-ui.badge color="primary" class="shrink-0">{{ $run->status->label() }}</x-ui.badge>
                                    </div>
                                    @if ($run->shipments_count > 0)
                                        @php $deliveredPct = (int) round($run->delivered_count / $run->shipments_count * 100); @endphp
                                        <div class="mt-1.5 h-1.5 rounded-full bg-gray-100 dark:bg-white/5">
                                            <div class="h-1.5 rounded-full bg-brand-500" style="width: {{ $deliveredPct }}%"></div>
                                        </div>
                                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $run->delivered_count }}/{{ $run->shipments_count }} delivered · {{ $run->posted_count }} posted</p>
                                    @else
                                        <p class="mt-1 text-theme-xs text-gray-400">{{ $run->creators_count }} {{ \Illuminate\Support\Str::plural('creator', $run->creators_count) }} · no shipments yet</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif
        </div>
    </div>

    {{-- Quick actions --}}
    <div>
        <p class="mb-2 text-theme-xs font-medium uppercase tracking-wide text-gray-400">Quick actions</p>
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
    </div>

    @can('users.manage')
        <p class="text-sm text-gray-500 dark:text-gray-400">
            User administration is available under Admin → Users.
        </p>
    @endcan
</div>
