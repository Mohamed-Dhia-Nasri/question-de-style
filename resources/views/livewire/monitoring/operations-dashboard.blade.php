<div>
    {{-- Freshness + queue health --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Queue depth</p>
            <p class="mt-2 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ number_format($queueDepth) }}</p>
            <p class="text-theme-xs text-gray-400">Failed jobs: {{ number_format($failedJobs) }}</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Snapshot freshness</p>
            <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">
                Accounts: {{ $snapshotFreshness['account'] ? \Illuminate\Support\Carbon::parse($snapshotFreshness['account'])->diffForHumans() : 'never' }}<br>
                Content: {{ $snapshotFreshness['content'] ? \Illuminate\Support\Carbon::parse($snapshotFreshness['content'])->diffForHumans() : 'never' }}
            </p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Analytics rollups</p>
            <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">
                @if ($analyticsRefresh)
                    <x-ui.badge :color="$analyticsRefresh->status === 'COMPLETED' ? 'success' : ($analyticsRefresh->status === 'FAILED' ? 'error' : 'warning')">
                        {{ $analyticsRefresh->status }}
                    </x-ui.badge>
                    {{ \Illuminate\Support\Carbon::parse($analyticsRefresh->started_at)->diffForHumans() }}
                @else
                    never refreshed
                @endif
            </p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Story polling</p>
            <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">
                Last archived story:
                {{ $storyFreshness ? \Illuminate\Support\Carbon::parse($storyFreshness)->diffForHumans() : 'never' }}
            </p>
            <p class="text-theme-xs text-gray-400">
                Last story cycle: {{ $lastStoryCycle?->started_at?->diffForHumans() ?? 'never' }}
                ({{ $lastStoryCycle?->status?->value ?? '—' }})
            </p>
        </div>
    </div>

    {{-- Ingestion cycles --}}
    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-800 dark:text-white/90">Last ingestion cycles</h3>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex items-center justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">Full cycle</dt>
                    <dd class="text-gray-700 dark:text-gray-200">
                        @if ($lastCycle)
                            <x-ui.badge color="light">{{ $lastCycle->status->value }}</x-ui.badge>
                            started {{ $lastCycle->started_at->diffForHumans() }} ·
                            {{ $lastCycle->accounts_count }} accounts · {{ $lastCycle->jobs_failed }} failed jobs
                        @else
                            never run
                        @endif
                    </dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">Story-only cycle</dt>
                    <dd class="text-gray-700 dark:text-gray-200">
                        @if ($lastStoryCycle)
                            <x-ui.badge color="light">{{ $lastStoryCycle->status->value }}</x-ui.badge>
                            started {{ $lastStoryCycle->started_at->diffForHumans() }}
                        @else
                            never run
                        @endif
                    </dd>
                </div>
            </dl>

            <h4 class="mt-4 text-sm font-semibold text-gray-700 dark:text-gray-200">Recent failed queue jobs</h4>
            <div class="mt-2 space-y-1">
                @forelse ($recentFailedJobs as $failed)
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                        #{{ $failed->id }} · queue {{ $failed->queue }} · {{ $failed->failed_at }}
                    </p>
                @empty
                    <p class="text-theme-xs text-gray-400">None.</p>
                @endforelse
            </div>

            <h4 class="mt-4 text-sm font-semibold text-gray-700 dark:text-gray-200">Failed exports</h4>
            <div class="mt-2 space-y-1">
                @forelse ($failedExports as $export)
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                        Export #{{ $export->id }} ({{ $export->format->value }}) failed {{ $export->failed_at?->diffForHumans() }} — {{ \Illuminate\Support\Str::limit($export->error, 80) }}
                    </p>
                @empty
                    <p class="text-theme-xs text-gray-400">None.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-800 dark:text-white/90">Recent alerts</h3>
            <div class="mt-3 space-y-2">
                @forelse ($alerts as $alert)
                    <div class="rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-800" wire:key="alert-{{ $alert->id }}">
                        <p class="text-sm text-gray-700 dark:text-gray-200">
                            <x-ui.badge color="warning">{{ $alert->alert_type }}</x-ui.badge>
                            {{ \Illuminate\Support\Str::limit($alert->message ?? '', 120) }}
                        </p>
                        <p class="text-theme-xs text-gray-400">{{ $alert->created_at?->diffForHumans() }}</p>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">No alerts.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Provider configuration + health (sanitized; staff-only screen) --}}
    <div class="mt-4">
        <x-table.container>
            <x-slot:header>
                <h3 class="font-semibold text-gray-800 dark:text-white/90">Provider health (SRC-* registry)</h3>
            </x-slot:header>
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead>
                    <tr>
                        <x-table.th>Source</x-table.th>
                        <x-table.th>Configured</x-table.th>
                        <x-table.th>Status</x-table.th>
                        <x-table.th>Last success</x-table.th>
                        <x-table.th>Success rate</x-table.th>
                        <x-table.th>p95 (ms)</x-table.th>
                        <x-table.th>Stale?</x-table.th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($providerHealth as $source => $state)
                        <tr wire:key="provider-{{ $source }}">
                            <td class="px-5 py-3 text-sm font-medium text-gray-700 dark:text-gray-200">{{ $source }}</td>
                            <td class="px-5 py-3">
                                <x-ui.badge :color="($providerConfig[$source] ?? false) ? 'success' : 'light'">
                                    {{ ($providerConfig[$source] ?? false) ? 'credentials set' : 'not configured' }}
                                </x-ui.badge>
                            </td>
                            <td class="px-5 py-3">
                                <x-ui.badge :color="match ($state['status']) { 'HEALTHY' => 'success', 'FAILING' => 'error', 'DEGRADED' => 'warning', default => 'light' }">
                                    {{ $state['status'] }}
                                </x-ui.badge>
                            </td>
                            <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $state['last_success_at'] ?? 'never' }}</td>
                            <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                {{ $state['success_rate'] !== null ? number_format($state['success_rate'] * 100, 1).'%' : '—' }}
                            </td>
                            <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $state['p95_duration_ms'] ?? '—' }}</td>
                            <td class="px-5 py-3">
                                @if ($state['stale_data_warning'])
                                    <x-ui.badge color="warning">stale</x-ui.badge>
                                @else
                                    <span class="text-theme-xs text-gray-400">no</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-table.container>
    </div>
</div>
