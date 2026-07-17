<div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4 dark:border-gray-800">
        <div>
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Communication log</h3>
            <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                The conversation history with this creator — outreach and replies, newest
                first.
            </p>
        </div>
        @can('create', \App\Modules\CRM\Models\CommunicationLog::class)
            <x-ui.button size="sm" wire:click="add">Log entry</x-ui.button>
        @endcan
    </div>

    @if ($logs->isEmpty())
        <x-states.empty title="No conversations logged yet">
            Keep the relationship history — outreach, replies, calls.
            <x-slot:action>
                @can('create', \App\Modules\CRM\Models\CommunicationLog::class)
                    <x-ui.button size="sm" wire:click="add">Log entry</x-ui.button>
                @endcan
            </x-slot:action>
        </x-states.empty>
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[800px]">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <x-table.th>Occurred</x-table.th>
                        <x-table.th>Channel</x-table.th>
                        <x-table.th>Direction</x-table.th>
                        <x-table.th>Summary</x-table.th>
                        <x-table.th><span class="sr-only">Actions</span></x-table.th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($logs as $log)
                        <tr wire:key="log-{{ $log->id }}">
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ $log->occurred_at->format('d.m.Y H:i') }}
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-800 dark:text-white/90">{{ $log->channel }}</td>
                            <td class="px-5 py-4">
                                <x-ui.badge :color="$log->direction === 'inbound' ? 'info' : 'light'">
                                    {{ $log->direction }}
                                </x-ui.badge>
                            </td>
                            <td class="max-w-md px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ $log->summary }}
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end">
                                    @can('update', $log)
                                        <button type="button" wire:click="edit({{ $log->id }})"
                                            class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                            Edit
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Create / edit modal --}}
    @if ($showForm)
        <x-ui.modal :title="$editingLogId ? 'Edit log entry' : 'Log communication'" close-action="cancelForm">
            <form wire:submit="save" class="space-y-5">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="log_channel" required>Channel</x-form.label>
                        <x-form.input id="log_channel" wire:model="log_channel"
                            :error="$errors->has('log_channel')" placeholder="Email / DM / call…" />
                        <x-form.error for="log_channel" />
                    </div>

                    <div>
                        <x-form.label for="log_direction" required>Direction</x-form.label>
                        <x-form.select id="log_direction" wire:model="log_direction"
                            :error="$errors->has('log_direction')">
                            <option value="">Select…</option>
                            <option value="outbound">outbound</option>
                            <option value="inbound">inbound</option>
                        </x-form.select>
                        <x-form.error for="log_direction" />
                    </div>
                </div>

                <div>
                    <x-form.label for="log_occurred_at" required>Occurred at</x-form.label>
                    <x-form.input id="log_occurred_at" wire:model="log_occurred_at" type="datetime-local"
                        :error="$errors->has('log_occurred_at')" />
                    <x-form.error for="log_occurred_at" />
                </div>

                <div>
                    <x-form.label for="log_summary" required>Summary</x-form.label>
                    <x-form.textarea id="log_summary" wire:model="log_summary" rows="3"
                        :error="$errors->has('log_summary')" placeholder="What happened" />
                    <x-form.error for="log_summary" />
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="log_campaign_id">Campaign (optional)</x-form.label>
                        <x-form.select id="log_campaign_id" wire:model="log_campaign_id"
                            :error="$errors->has('log_campaign_id')">
                            <option value="">No campaign</option>
                            @foreach ($campaigns as $campaignOption)
                                <option value="{{ $campaignOption->id }}">{{ $campaignOption->name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-form.error for="log_campaign_id" />
                    </div>

                    <div>
                        <x-form.label for="log_seeding_campaign_id">Seeding run (optional)</x-form.label>
                        <x-form.select id="log_seeding_campaign_id" wire:model="log_seeding_campaign_id"
                            :error="$errors->has('log_seeding_campaign_id')">
                            <option value="">No seeding run</option>
                            @foreach ($seedingRuns as $seedingRunOption)
                                <option value="{{ $seedingRunOption->id }}">{{ $seedingRunOption->name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-form.error for="log_seeding_campaign_id" />
                    </div>
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelForm" wire:loading.attr="disabled">
                    Cancel
                </x-ui.button>
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editingLogId ? 'Save changes' : 'Add entry' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
