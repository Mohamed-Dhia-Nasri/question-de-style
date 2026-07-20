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

    {{-- AI spend & visual-match quality (spec §10 — budget governance) --}}
    <div class="mt-4 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 class="font-semibold text-gray-800 dark:text-white/90">AI spend</h3>
        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
            Estimated from list prices — Google bills the truth. Platform totals are anonymous aggregates;
            only your own workspace's usage is itemized.
        </p>

        <div class="mt-3 overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-theme-xs uppercase tracking-wide text-gray-400 dark:border-gray-800 dark:text-gray-500">
                        <th scope="col" class="py-2 pr-4 font-medium">Capability</th>
                        <th scope="col" class="py-2 pr-4 text-right font-medium">Calls today</th>
                        <th scope="col" class="py-2 pr-4 text-right font-medium">Calls this month</th>
                        <th scope="col" class="py-2 pr-4 text-right font-medium">Est. spend (month)</th>
                        <th scope="col" class="py-2 pr-4 text-right font-medium">Skipped budget / no candidates</th>
                        <th scope="col" class="py-2 pr-4 text-right font-medium">Avg cost / post</th>
                        <th scope="col" class="py-2 text-right font-medium">Platform today / month</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($aiSpend['capabilities'] as $row)
                        <tr wire:key="ai-spend-{{ $row['capability'] }}">
                            <td class="py-3 pr-4 font-medium text-gray-800 dark:text-white/90">{{ $row['capability'] }}</td>
                            <td class="py-3 pr-4 text-right text-gray-600 dark:text-gray-300">{{ number_format($row['own_today_units']) }}</td>
                            <td class="py-3 pr-4 text-right text-gray-600 dark:text-gray-300">{{ number_format($row['own_month_units']) }}</td>
                            <td class="py-3 pr-4 text-right text-gray-600 dark:text-gray-300">${{ number_format($row['own_month_cost_usd'], 2) }}</td>
                            <td class="py-3 pr-4 text-right text-gray-600 dark:text-gray-300">{{ number_format($row['own_skipped_budget']) }} / {{ number_format($row['own_skipped_no_candidates']) }}</td>
                            <td class="py-3 pr-4 text-right text-gray-600 dark:text-gray-300">{{ $row['avg_cost_per_post_usd'] === null ? '—' : '$'.number_format($row['avg_cost_per_post_usd'], 4) }}</td>
                            <td class="py-3 text-right text-gray-600 dark:text-gray-300">{{ number_format($row['global_today_units']) }} / {{ number_format($row['global_month_units']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-3 text-sm text-gray-400">No AI capabilities configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($aiSpend['visual'] !== null)
            <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-800">
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">Cache-hit rate (7 d)</p>
                    <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-white/90">
                        {{ $aiSpend['visual']['cache_hit_rate'] === null ? '—' : number_format($aiSpend['visual']['cache_hit_rate'] * 100, 1).'%' }}
                    </p>
                    <p class="text-theme-xs text-gray-400">{{ number_format($aiSpend['visual']['embeddings_created']) }} embeddings created</p>
                </div>
                <div class="rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-800">
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">Frame skips (format / quality / dedup)</p>
                    <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-white/90">
                        {{ number_format($aiSpend['visual']['skipped_format']) }} / {{ number_format($aiSpend['visual']['skipped_quality']) }} / {{ number_format($aiSpend['visual']['deduped']) }}
                    </p>
                </div>
                <div class="rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-800">
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">Budget denials (7 d)</p>
                    <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-white/90">{{ number_format($aiSpend['visual']['budget_denials']) }}</p>
                    <p class="text-theme-xs text-gray-400">of {{ number_format($aiSpend['visual']['runs']) }} runs</p>
                </div>
                <div class="rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-800">
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">Avg candidates / processing</p>
                    <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-white/90">
                        {{ $aiSpend['visual']['avg_candidates'] }} · {{ number_format($aiSpend['visual']['avg_processing_ms']) }} ms
                    </p>
                </div>
            </div>
        @else
            <p class="mt-3 text-theme-xs text-gray-400">No visual-match runs in the last 7 days.</p>
        @endif
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
