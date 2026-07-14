<div>
    <div class="grid gap-4 lg:grid-cols-3">
        {{-- Media preview (authorized staff only — page is monitoring.view) --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-800 dark:text-white/90">Media</h3>
            <div class="mt-3 space-y-3">
                @forelse ($content->media_urls ?? [] as $url)
                    @if (\Illuminate\Support\Str::of(parse_url($url, PHP_URL_PATH) ?? '')->lower()->endsWith(['.jpg', '.jpeg', '.png', '.webp', '.gif']))
                        <img src="{{ $url }}" alt="Content media preview" loading="lazy"
                            class="max-h-72 w-full rounded-xl object-cover" />
                    @else
                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                            class="block truncate text-sm text-brand-500 hover:underline">{{ $url }}</a>
                    @endif
                @empty
                    <x-states.unavailable reason="No public media reference was returned by the source." />
                @endforelse
            </div>

            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Platform</dt>
                    <dd><x-ui.badge color="light">{{ $content->platform->value }}</x-ui.badge></dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Content type</dt>
                    <dd><x-ui.badge color="light">{{ $content->content_type->value }}</x-ui.badge></dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Published</dt>
                    <dd class="text-gray-700 dark:text-gray-200">{{ $content->published_at?->format('Y-m-d H:i') ?? 'unknown' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Creator</dt>
                    <dd class="text-gray-700 dark:text-gray-200">
                        @if ($content->platformAccount?->creator)
                            <a class="text-brand-500 hover:underline"
                                href="{{ route('monitoring.creators.show', $content->platformAccount->creator) }}">
                                {{ $content->platformAccount->creator->display_name }}
                            </a>
                        @else
                            {{ '@'.$content->platformAccount?->handle }}
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Provenance</dt>
                    <dd class="text-right text-theme-xs text-gray-500 dark:text-gray-400">
                        {{ $content->provenance->source }}<br>
                        fetched {{ $content->provenance->fetchedAt->format('Y-m-d H:i') }} · v{{ $content->provenance->sourceVersion }}
                    </dd>
                </div>
            </dl>

            @if ($content->caption)
                <p class="mt-4 whitespace-pre-line rounded-xl bg-gray-50 p-3 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-200">{{ $content->caption }}</p>
            @endif
        </div>

        {{-- Metrics: PUBLIC facts, DERIVED rates, tiered reach (AC-M1-006/007) --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 lg:col-span-2 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-800 dark:text-white/90">Metrics</h3>

            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                @forelse ($latestSnapshot?->metrics ?? $content->public_metrics ?? [] as $metric)
                    <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                        <p class="text-theme-xs uppercase text-gray-400">{{ $metric->metric ?? 'metric' }}</p>
                        <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{{ number_format($metric->amount) }}</p>
                        <x-metric.tier-badge :tier="$metric->tier" />
                    </div>
                @empty
                    <div class="sm:col-span-3">
                        <x-states.unavailable reason="No metric snapshot captured yet (SVC-SnapshotScheduler, ADR-0003)." />
                    </div>
                @endforelse
            </div>

            <h4 class="mt-5 text-sm font-semibold text-gray-700 dark:text-gray-200">Derived rates (never PUBLIC — DP-001)</h4>
            <div class="mt-2 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                    <p class="text-theme-xs uppercase text-gray-400" title="MET-EngagementRate: (likes + comments + shares + saves) / {{ $engagementBase }}">Engagement rate</p>
                    <div class="mt-1"><x-metric.value :metric="$engagementRate" :decimals="4" reason="Missing engagement components or base — never shown as zero." /></div>
                </div>
                <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                    <p class="text-theme-xs uppercase text-gray-400" title="MET-ViewRate: views / followers">View rate</p>
                    <div class="mt-1"><x-metric.value :metric="$viewRate" :decimals="4" reason="Missing observed views or follower count." /></div>
                </div>
                <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                    <p class="text-theme-xs uppercase text-gray-400" title="MET-CommentRate: comments / {{ $engagementBase }}">Comment rate</p>
                    <div class="mt-1"><x-metric.value :metric="$commentRate" :decimals="4" reason="Missing observed comments or base." /></div>
                </div>
            </div>
            <p class="mt-1 text-theme-xs text-gray-400">Engagement base: {{ $engagementBase }} (configured, disclosed with every rate).</p>

            <h4 class="mt-5 text-sm font-semibold text-gray-700 dark:text-gray-200">Reach (REQ-M1-006)</h4>
            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                    <p class="text-theme-xs uppercase text-gray-400">Estimated reach</p>
                    <div class="mt-1">
                        @if ($latestReach)
                            <span class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ number_format($latestReach->value->amount) }}</span>
                            <x-metric.tier-badge tier="ESTIMATED" />
                            <p class="mt-0.5 text-theme-xs text-gray-400">method: {{ $latestReach->value->method }}</p>
                        @else
                            <x-states.unavailable reason="Estimated reach is unavailable until a reach configuration is active and this content has been enriched (REQ-M1-006)." />
                        @endif
                    </div>
                </div>
            </div>

            <h4 class="mt-5 text-sm font-semibold text-gray-700 dark:text-gray-200">EMV (REQ-M1-011)</h4>
            <div class="mt-2 rounded-xl border border-gray-100 p-3 dark:border-gray-800">
                @if ($latestEmv !== null)
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-lg font-semibold text-gray-800 dark:text-white/90">
                            {{ number_format($latestEmv->value->amount, 2) }} {{ $latestEmv->currency }}
                        </span>
                        <x-metric.tier-badge :tier="$latestEmv->value->tier" />
                    </div>
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                        Model "{{ $latestEmv->configuration?->name }}" · formula {{ $latestEmv->formula_version }} ·
                        rate card {{ $latestEmv->rate_card_version }} · calculated {{ $latestEmv->calculated_at->format('Y-m-d H:i') }}.
                        Rates: {{ json_encode($latestEmv->configuration?->rates) }}
                    </p>
                @else
                    <x-states.unavailable reason="EMV requires an active, user-managed configuration and a calculated result (REQ-M1-011)." />
                @endif
            </div>

            <h4 class="mt-5 text-sm font-semibold text-gray-700 dark:text-gray-200">Comment analysis</h4>
            <div class="mt-2">
                <x-states.unavailable reason="Comment collection & audience-reaction analysis are deferred (DEF-005, ADR-0009)." />
            </div>
        </div>
    </div>

    {{-- AI assessments with confidence, verification, provenance + review actions (DP-004) --}}
    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-800 dark:text-white/90">Mentions</h3>
            <div class="mt-3 space-y-3">
                @forelse ($content->mentions as $mention)
                    <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800" wire:key="cd-mention-{{ $mention->id }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge :color="$mention->mention_type->value === 'SEEDED' ? 'success' : 'light'">{{ $mention->mention_type->value }}</x-ui.badge>
                            <span class="text-sm text-gray-700 dark:text-gray-200">{{ $mention->monitoredSubject?->label }}</span>
                        </div>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                            Confidence: {{ $mention->classification->confidenceLevel->value }} ·
                            {{ $mention->classification->verificationStatus->value }} ·
                            Source: {{ $mention->provenance->source }}
                        </p>
                        @if ($mention->classification->signals !== [])
                            <p class="mt-1 text-theme-xs text-gray-400">Signals: {{ implode('; ', array_map(fn ($s) => is_scalar($s) ? (string) $s : json_encode($s), $mention->classification->signals)) }}</p>
                        @endif

                        @can('update', $mention)
                            <div class="mt-2 flex flex-wrap gap-2">
                                <x-ui.button size="sm" variant="outline" wire:click="approve('mention', {{ $mention->id }})">Approve</x-ui.button>
                                <x-ui.button size="sm" variant="outline" wire:click="openForm('mention', {{ $mention->id }})">Correct</x-ui.button>
                                <x-ui.button size="sm" variant="outline" wire:click="reject('mention', {{ $mention->id }})">Reject</x-ui.button>
                            </div>
                            @if ($actingOn === 'mention:'.$mention->id)
                                <div class="mt-2 space-y-2">
                                    <x-form.select wire:model="correctionMentionType" aria-label="Corrected mention type">
                                        <option value="">Corrected type…</option>
                                        @foreach ($mentionTypes as $type)
                                            <option value="{{ $type->value }}">{{ $type->value }}</option>
                                        @endforeach
                                    </x-form.select>
                                    <x-form.input wire:model="reason" placeholder="Reason (optional)" />
                                    <x-ui.button size="sm" wire:click="correct('mention', {{ $mention->id }})">Save correction</x-ui.button>
                                </div>
                            @endif
                        @endcan
                    </div>
                @empty
                    <x-states.empty title="No mentions on this content" />
                @endforelse
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-800 dark:text-white/90">Brand recognition</h3>
            <div class="mt-3 space-y-3">
                @forelse ($content->recognitionDetections as $detection)
                    <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800" wire:key="cd-recognition-{{ $detection->id }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge color="info">{{ $detection->recognition_type->value }}</x-ui.badge>
                            <span class="text-sm text-gray-700 dark:text-gray-200">{{ $detection->detected_brand ?? $detection->detected_text ?? '—' }}</span>
                        </div>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                            Confidence: {{ $detection->assessment->confidenceLevel->value }} ·
                            {{ $detection->assessment->verificationStatus->value }} ·
                            Source: {{ $detection->provenance->source }}
                        </p>
                    </div>
                @empty
                    <x-states.empty title="No recognition detections" />
                @endforelse
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-800 dark:text-white/90">Sentiment</h3>
            <div class="mt-3 space-y-3">
                @forelse ($content->sentimentAnalyses as $sentiment)
                    <div class="rounded-xl border border-gray-100 p-3 dark:border-gray-800" wire:key="cd-sentiment-{{ $sentiment->id }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge :color="match ($sentiment->label->value) { 'POSITIVE' => 'success', 'NEGATIVE' => 'error', 'MIXED' => 'warning', default => 'light' }">
                                {{ $sentiment->label->value }}
                            </x-ui.badge>
                            <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                {{ $sentiment->assessment->confidenceLevel->value }} · {{ $sentiment->assessment->verificationStatus->value }}
                            </span>
                        </div>
                        @if ($sentiment->context_summary)
                            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $sentiment->context_summary }}</p>
                        @endif

                        @can('update', $sentiment)
                            <div class="mt-2 flex flex-wrap gap-2">
                                <x-ui.button size="sm" variant="outline" wire:click="approve('sentiment', {{ $sentiment->id }})">Approve</x-ui.button>
                                <x-ui.button size="sm" variant="outline" wire:click="openForm('sentiment', {{ $sentiment->id }})">Correct</x-ui.button>
                                <x-ui.button size="sm" variant="outline" wire:click="reject('sentiment', {{ $sentiment->id }})">Reject</x-ui.button>
                            </div>
                            @if ($actingOn === 'sentiment:'.$sentiment->id)
                                <div class="mt-2 space-y-2">
                                    <x-form.select wire:model="correctionSentiment" aria-label="Corrected sentiment">
                                        <option value="">Corrected label…</option>
                                        @foreach ($sentimentLabels as $label)
                                            <option value="{{ $label->value }}">{{ $label->value }}</option>
                                        @endforeach
                                    </x-form.select>
                                    <x-form.input wire:model="reason" placeholder="Reason (optional)" />
                                    <x-ui.button size="sm" wire:click="correct('sentiment', {{ $sentiment->id }})">Save correction</x-ui.button>
                                </div>
                            @endif
                        @endcan
                    </div>
                @empty
                    <x-states.empty title="No sentiment analyses" />
                @endforelse
            </div>
        </div>
    </div>

    {{-- Reviewer + timestamp history (DP-004 audit of decisions) --}}
    <div class="mt-4">
        <x-table.container>
            <x-slot:header>
                <h3 class="font-semibold text-gray-800 dark:text-white/90">Review history</h3>
            </x-slot:header>
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead>
                    <tr>
                        <x-table.th>When</x-table.th>
                        <x-table.th>Reviewer</x-table.th>
                        <x-table.th>Action</x-table.th>
                        <x-table.th>Subject</x-table.th>
                        <x-table.th>Reason</x-table.th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($reviewHistory as $action)
                        <tr wire:key="history-{{ $action->id }}">
                            <td class="px-5 py-2 text-sm text-gray-600 dark:text-gray-300">{{ $action->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-5 py-2 text-sm text-gray-600 dark:text-gray-300">{{ $action->user?->display_name ?? 'Removed user #'.$action->actor_id }}</td>
                            <td class="px-5 py-2"><x-ui.badge color="light">{{ $action->action }}</x-ui.badge></td>
                            <td class="px-5 py-2 text-sm text-gray-600 dark:text-gray-300">{{ class_basename($action->reviewable_type) }} #{{ $action->reviewable_id }}</td>
                            <td class="px-5 py-2 text-sm text-gray-600 dark:text-gray-300">{{ $action->reason ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5"><x-states.empty title="No review decisions yet" /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-table.container>
    </div>
</div>
