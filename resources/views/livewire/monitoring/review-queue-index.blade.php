<div class="space-y-4">
    {{-- Kind filter + queue counts --}}
    <div class="flex flex-wrap items-center gap-2">
        <button type="button" wire:click="$set('kind', '')"
            class="rounded-full px-3 py-1 text-sm {{ $kind === '' ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' }}">
            All
        </button>
        @foreach ($kinds as $queueKind)
            <button type="button" wire:click="$set('kind', '{{ $queueKind }}')"
                class="rounded-full px-3 py-1 text-sm {{ $kind === $queueKind ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' }}">
                {{ ucfirst($queueKind) }}
                <span class="ml-1 text-xs opacity-70">{{ $counts[$queueKind] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @if ($items->isEmpty())
            <x-states.empty title="Review queue is clear">
                No low-confidence AI outputs or ambiguous hashtag matches await review (DP-004).
            </x-states.empty>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($items as $entry)
                    @php
                        $itemKind = $entry['kind'];
                        $item = $entry['item'];
                        $key = $itemKind.':'.$item->id;
                        $envelope = match ($itemKind) {
                            'mention' => $item->classification,
                            'recognition', 'sentiment' => $item->assessment,
                            default => null,
                        };
                    @endphp
                    <li class="p-4" wire:key="{{ $key }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.badge>{{ ucfirst($itemKind) }}</x-ui.badge>

                                    @if ($itemKind === 'mention')
                                        <span class="font-medium">{{ $item->mention_type->value }}</span>
                                    @elseif ($itemKind === 'recognition')
                                        <span class="font-medium">{{ $item->recognition_type->value }}</span>
                                        <span class="text-gray-500">{{ $item->detected_brand }}</span>
                                    @elseif ($itemKind === 'sentiment')
                                        <span class="font-medium">{{ $item->label->value }}</span>
                                    @else
                                        <span class="font-medium">{{ $item->original }}</span>
                                        <span class="text-xs text-gray-500">matches {{ count($item->matches ?? []) }} lists</span>
                                    @endif

                                    @if ($envelope !== null)
                                        <x-ui.badge color="warning">{{ $envelope->confidenceLevel->value }}</x-ui.badge>
                                        <x-ui.badge color="light">{{ $envelope->verificationStatus->value }}</x-ui.badge>
                                    @endif
                                </div>

                                {{-- Evidence display: the signals behind the AI output (DP-003) --}}
                                <div class="mt-2 flex flex-wrap gap-1 text-xs text-gray-500 dark:text-gray-400">
                                    @if ($envelope !== null)
                                        @foreach ($envelope->signals as $signal)
                                            <span class="rounded bg-gray-50 px-1.5 py-0.5 dark:bg-gray-800">{{ $signal }}</span>
                                        @endforeach
                                    @elseif ($itemKind === 'hashtag')
                                        @foreach ($item->matches ?? [] as $match)
                                            <span class="rounded bg-gray-50 px-1.5 py-0.5 dark:bg-gray-800">
                                                #{{ $match['hashtag_list_id'] }} · {{ $match['scope'] }}
                                            </span>
                                        @endforeach
                                    @endif
                                </div>

                                @if ($item->contentItem ?? null)
                                    <p class="mt-2 line-clamp-2 max-w-xl text-sm text-gray-600 dark:text-gray-300">
                                        {{ \Illuminate\Support\Str::limit((string) $item->contentItem->caption, 180) }}
                                    </p>
                                @endif
                            </div>

                            <div class="flex shrink-0 gap-2">
                                @can('update', $item)
                                    @if ($itemKind !== 'hashtag')
                                        <x-ui.button size="sm" wire:click="approve('{{ $itemKind }}', {{ $item->id }})">Approve</x-ui.button>
                                    @endif
                                    <x-ui.button size="sm" variant="outline" wire:click="openForm('{{ $itemKind }}', {{ $item->id }})">Correct</x-ui.button>
                                    <x-ui.button size="sm" variant="outline" wire:click="reject('{{ $itemKind }}', {{ $item->id }})">Reject</x-ui.button>
                                    <x-ui.button size="sm" variant="outline" wire:click="unresolved('{{ $itemKind }}', {{ $item->id }})">Leave</x-ui.button>
                                @endcan
                            </div>
                        </div>

                        @if ($actingOn === $key)
                            <div class="mt-3 rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    @if ($itemKind === 'mention')
                                        <div>
                                            <x-form.label for="correctionMentionType">Corrected classification</x-form.label>
                                            <x-form.select id="correctionMentionType" wire:model="correctionMentionType">
                                                <option value="">—</option>
                                                @foreach ($mentionTypes as $type)
                                                    <option value="{{ $type->value }}">{{ $type->value }}</option>
                                                @endforeach
                                            </x-form.select>
                                        </div>
                                    @elseif ($itemKind === 'recognition')
                                        <div>
                                            <x-form.label for="correctionBrand">Corrected brand</x-form.label>
                                            <x-form.input id="correctionBrand" type="text" wire:model="correctionBrand" />
                                        </div>
                                    @elseif ($itemKind === 'sentiment')
                                        <div>
                                            <x-form.label for="correctionSentiment">Corrected sentiment</x-form.label>
                                            <x-form.select id="correctionSentiment" wire:model="correctionSentiment">
                                                <option value="">—</option>
                                                @foreach ($sentimentLabels as $label)
                                                    <option value="{{ $label->value }}">{{ $label->value }}</option>
                                                @endforeach
                                            </x-form.select>
                                        </div>
                                    @else
                                        <div>
                                            <x-form.label for="correctionHashtagListId">Resolve to hashtag list entry</x-form.label>
                                            <x-form.select id="correctionHashtagListId" wire:model="correctionHashtagListId">
                                                <option value="">—</option>
                                                @foreach ($item->matches ?? [] as $match)
                                                    <option value="{{ $match['hashtag_list_id'] }}">
                                                        {{ $match['scope'] }} #{{ $match['hashtag_list_id'] }}
                                                    </option>
                                                @endforeach
                                            </x-form.select>
                                        </div>
                                    @endif

                                    <div>
                                        <x-form.label for="reason">Reason (required for PAID/SEEDED corrections)</x-form.label>
                                        <x-form.input id="reason" type="text" wire:model="reason" placeholder="e.g. shipment #42 confirmed" />
                                    </div>
                                </div>

                                <div class="mt-3 flex gap-2">
                                    <x-ui.button size="sm" wire:click="correct('{{ $itemKind }}', {{ $item->id }})">Save correction</x-ui.button>
                                    <x-ui.button size="sm" variant="outline" wire:click="closeForm">Cancel</x-ui.button>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
