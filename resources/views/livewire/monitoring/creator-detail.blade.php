<div>
    {{-- Creator + platform-account summary --}}
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="flex items-center gap-3">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $creator->display_name }}</h3>
                    @can('crm.view')
                        <a href="{{ route('crm.creators.show', $creator) }}"
                            class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                            View CRM profile →
                        </a>
                    @endcan
                </div>
                @can('create', \App\Modules\Monitoring\Models\MonitoredSubject::class)
                    <x-ui.button variant="outline" type="button" wire:click="runMonitoringNow"
                        wire:loading.attr="disabled" wire:target="runMonitoringNow"
                        title="Poll this creator's accounts now instead of waiting for the scheduled monitoring cycle.">
                        <span wire:loading.remove wire:target="runMonitoringNow">Run monitoring now</span>
                        <span wire:loading wire:target="runMonitoringNow">Starting…</span>
                    </x-ui.button>
                @endcan
            </div>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Relationship: {{ $creator->relationship_status?->value ?? 'NONE' }}
                @if ($creator->primary_language) · Language: {{ $creator->primary_language }} @endif
            </p>
            <x-data-freshness :at="$dataUpdatedAt" label="Data updated" never="not pulled yet" class="mt-1 block" />
            <div class="mt-3 space-y-2">
                @forelse ($creator->platformAccounts as $account)
                    <div class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2 dark:border-gray-800">
                        <span class="text-sm text-gray-700 dark:text-gray-200">
                            <x-ui.badge color="light">{{ $account->platform->value }}</x-ui.badge>
                            {{ '@'.$account->handle }}
                        </span>
                        <span class="text-sm">
                            <x-metric.value :metric="$account->follower_count" reason="No follower count observed yet." />
                        </span>
                    </div>
                @empty
                    <x-states.empty title="No platform accounts linked" />
                @endforelse
            </div>
            <div class="mt-4 space-y-2 text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-gray-500 dark:text-gray-400">Audience demographics</span>
                    <x-states.unavailable reason="Audience country/age/gender is deferred (DEF-001, ADR-0004)." />
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-gray-500 dark:text-gray-400">Contact auto-extraction</span>
                    <x-states.unavailable reason="Contact auto-extraction is deferred (DEF-002, ADR-0005); contacts are manual CRM entries." />
                </div>
            </div>
        </div>

        {{-- Follower growth (ROLLUP-CreatorByPeriod, own-DB snapshots) --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 lg:col-span-2 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-semibold text-gray-800 dark:text-white/90">Follower growth & performance by period</h3>
                <div class="w-36">
                    <x-form.select id="creator-grain" wire:model.live="grain" aria-label="Period grain">
                        @foreach ($grains as $g)
                            <option value="{{ $g }}">{{ ucfirst($g) }}</option>
                        @endforeach
                    </x-form.select>
                </div>
            </div>

            @if ($series->isEmpty())
                <div class="mt-4">
                    <x-states.unavailable reason="No snapshots rolled up yet for this creator — history accrues from own-DB snapshots only (ADR-0003)." />
                </div>
            @else
                @php
                    $followerPoints = $series->filter(fn ($b) => $b->followers !== null)->values();
                    $maxFollowers = max(1.0, (float) $followerPoints->max(fn ($b) => (float) $b->followers));
                @endphp
                @if ($followerPoints->isNotEmpty())
                    <svg viewBox="0 0 600 160" class="mt-4 h-40 w-full" role="img"
                        aria-label="Follower count per {{ $grain }} bucket (PUBLIC tier)">
                        @foreach ($followerPoints as $i => $bucket)
                            @php
                                $barWidth = 600 / max(1, $followerPoints->count());
                                $height = (float) $bucket->followers / $maxFollowers * 140;
                            @endphp
                            <rect x="{{ $i * $barWidth + 2 }}" y="{{ 150 - $height }}"
                                width="{{ max(1, $barWidth - 4) }}" height="{{ max(1, $height) }}"
                                class="fill-brand-500" rx="2">
                                <title>{{ $bucket->bucket_start }}: {{ number_format((float) $bucket->followers) }} followers (PUBLIC)</title>
                            </rect>
                        @endforeach
                    </svg>
                @endif

                <div class="mt-3 max-w-full overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                        <thead>
                            <tr>
                                <x-table.th>Bucket</x-table.th>
                                <x-table.th>Followers</x-table.th>
                                <x-table.th>Growth</x-table.th>
                                <x-table.th>Avg views</x-table.th>
                                <x-table.th>Engagement rate</x-table.th>
                                <x-table.th>Posting freq.</x-table.th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($series as $bucket)
                                <tr wire:key="bucket-{{ $bucket->grain }}-{{ $bucket->bucket_start }}">
                                    <td class="px-5 py-2 text-gray-600 dark:text-gray-300">{{ $bucket->bucket_start }}</td>
                                    <td class="px-5 py-2">
                                        @if ($bucket->followers !== null)
                                            {{ number_format((float) $bucket->followers) }} <x-metric.tier-badge tier="PUBLIC" />
                                        @else
                                            <x-states.unavailable reason="No account snapshot in this bucket." />
                                        @endif
                                    </td>
                                    <td class="px-5 py-2">
                                        @if ($bucket->follower_growth !== null)
                                            {{ number_format((float) $bucket->follower_growth) }} <x-metric.tier-badge tier="DERIVED" />
                                        @else
                                            <x-states.unavailable reason="Growth needs at least two snapshots in the bucket." />
                                        @endif
                                    </td>
                                    <td class="px-5 py-2">
                                        @if ($bucket->avg_views !== null)
                                            {{ number_format((float) $bucket->avg_views) }} <x-metric.tier-badge tier="DERIVED" />
                                        @else
                                            <x-states.unavailable reason="No observed content views in this bucket." />
                                        @endif
                                    </td>
                                    <td class="px-5 py-2">
                                        @if ($bucket->engagement_rate !== null)
                                            {{ number_format((float) $bucket->engagement_rate, 4) }} <x-metric.tier-badge tier="DERIVED" />
                                        @else
                                            <x-states.unavailable reason="Needs observed engagement and followers in this bucket." />
                                        @endif
                                    </td>
                                    <td class="px-5 py-2">
                                        <x-states.unavailable reason="No canonical posting-frequency formula (flagged decision gap)." />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-theme-xs text-gray-400 dark:text-gray-500">
                    Rollups refreshed {{ $rollupsRefreshedAt?->diffForHumans() ?? 'never' }}. DERIVED rates are recomputed at the bucket grain, never summed (DP-001).
                </p>
            @endif
        </div>
    </div>

    {{-- Average / median performance (DERIVED, never PUBLIC) --}}
    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Average views (recent content)</p>
            <div class="mt-2">
                <x-metric.value :metric="$averagePerformance" reason="No observed views on the listed content." />
            </div>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Median views (recent content)</p>
            <div class="mt-2">
                <x-metric.value :metric="$medianPerformance" reason="No observed views on the listed content." />
            </div>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Engagement trend (last {{ $trendWindowDays }} days)</p>
            <div class="mt-2">
                @if ($engagementTrend !== null)
                    <div class="flex items-center gap-2">
                        <span class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ $engagementTrend->percentChange >= 0 ? '+' : '' }}{{ $engagementTrend->percentChange }}%
                        </span>
                        <x-metric.tier-badge tier="DERIVED" />
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Average likes + comments per post vs the {{ $trendWindowDays }} days before
                        ({{ $engagementTrend->currentCount }} vs {{ $engagementTrend->previousCount }} posts).
                    </p>
                @else
                    <x-states.unavailable reason="Not enough posts in the two comparison windows yet." />
                @endif
            </div>
        </div>
    </div>

    {{-- Recent content (paginated, server-side filters) --}}
    <div class="mt-4">
        <x-table.container>
            <x-slot:header>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-semibold text-gray-800 dark:text-white/90">Recent content</h3>
                    <div class="flex gap-2">
                        <x-form.select id="creator-content-platform" wire:model.live="platform" aria-label="Platform filter">
                            <option value="">All platforms</option>
                            @foreach ($platforms as $p)
                                <option value="{{ $p->value }}">{{ $p->value }}</option>
                            @endforeach
                        </x-form.select>
                        <x-form.select id="creator-content-type" wire:model.live="contentType" aria-label="Content type filter">
                            <option value="">All content types</option>
                            @foreach ($contentTypes as $type)
                                <option value="{{ $type->value }}">{{ $type->value }}</option>
                            @endforeach
                        </x-form.select>
                    </div>
                </div>
            </x-slot:header>

            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead>
                    <tr>
                        <x-table.th>Content</x-table.th>
                        <x-table.th>Type</x-table.th>
                        <x-table.th>Published</x-table.th>
                        <x-table.th>Public metrics</x-table.th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($recentContent as $item)
                        <tr wire:key="content-{{ $item->id }}">
                            <td class="px-5 py-3">
                                <a href="{{ route('monitoring.content.show', $item) }}" class="font-medium text-brand-500 hover:underline">
                                    {{ \Illuminate\Support\Str::limit($item->caption ?? '(no caption)', 60) }}
                                </a>
                                <p class="text-theme-xs text-gray-400">{{ $item->platform->value }}</p>
                            </td>
                            <td class="px-5 py-3"><x-ui.badge color="light">{{ $item->content_type->value }}</x-ui.badge></td>
                            <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                {{ $item->published_at?->format('Y-m-d H:i') ?? '—' }}
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex flex-wrap gap-2">
                                    @forelse ($item->public_metrics ?? [] as $metric)
                                        <span class="text-theme-xs text-gray-600 dark:text-gray-300">
                                            {{ $metric->metric }}: {{ number_format($metric->amount) }}
                                            <x-metric.tier-badge :tier="$metric->tier" />
                                        </span>
                                    @empty
                                        <x-states.unavailable reason="No public metrics observed for this content." />
                                    @endforelse
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4"><x-states.empty title="No content collected yet" /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <x-slot:footer>{{ $recentContent->links() }}</x-slot:footer>
        </x-table.container>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        {{-- Recent stories (ENT-Story — never a ContentItem) --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-800 dark:text-white/90">Recent stories</h3>
            <div class="mt-3 space-y-2">
                @forelse ($recentStories as $story)
                    <div class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2 text-sm dark:border-gray-800" wire:key="story-{{ $story->id }}">
                        <span class="text-gray-600 dark:text-gray-300">
                            <x-ui.badge color="light">{{ $story->platform->value }}</x-ui.badge>
                            captured {{ $story->captured_at->diffForHumans() }}
                        </span>
                        <span class="text-theme-xs text-gray-400">
                            {{ $story->expires_at !== null ? ($story->expires_at->isFuture() ? 'expires '.$story->expires_at->diffForHumans() : 'expired') : 'expiry unknown' }}
                        </span>
                    </div>
                @empty
                    <x-states.empty title="No stories archived yet" />
                @endforelse
            </div>
        </div>

        {{-- Mentions with classification / verification / provenance --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-800 dark:text-white/90">Mentions</h3>
            <div class="mt-3 space-y-2">
                @forelse ($mentions as $mention)
                    <div class="rounded-lg border border-gray-100 px-3 py-2 text-sm dark:border-gray-800" wire:key="mention-{{ $mention->id }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge :color="$mention->mention_type->value === 'SEEDED' ? 'success' : ($mention->mention_type->value === 'PAID' ? 'warning' : 'light')">
                                {{ $mention->mention_type->value }}
                            </x-ui.badge>
                            <span class="text-gray-700 dark:text-gray-200">{{ $mention->monitoredSubject?->label }}</span>
                            <x-ui.badge color="info">{{ $mention->classification->confidenceLevel->value }}</x-ui.badge>
                            <x-ui.badge color="light">{{ $mention->classification->verificationStatus->value }}</x-ui.badge>
                        </div>
                        <p class="mt-1 text-theme-xs text-gray-400">
                            Provenance: {{ $mention->provenance->source }} · fetched {{ $mention->provenance->fetchedAt->diffForHumans() }}
                            @if ($mention->contentItem)
                                · <a class="text-brand-500 hover:underline" href="{{ route('monitoring.content.show', $mention->contentItem) }}">content</a>
                            @elseif ($mention->story)
                                · story #{{ $mention->story->id }}
                            @endif
                        </p>
                    </div>
                @empty
                    <x-states.empty title="No mentions detected yet" />
                @endforelse
            </div>
        </div>
    </div>
</div>
