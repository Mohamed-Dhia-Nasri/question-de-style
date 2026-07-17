<div>
    {{-- Overdue / Due-soon sections (the D9 in-app reminder channel): the
         chips summarize and, on click, filter to the flagged population. --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <button type="button" wire:click="setDueWindow('overdue')"
            class="rounded-2xl border p-5 text-left transition-colors {{ $dueWindow === 'overdue'
                ? 'border-error-500 bg-error-50 dark:border-error-500/60 dark:bg-error-500/10'
                : 'border-gray-200 bg-white hover:border-error-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-error-500/40' }}">
            <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Overdue</p>
            <p class="mt-1 text-2xl font-semibold text-error-600 dark:text-error-500">{{ $overdueCount }}</p>
            <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                Open tasks past their deadline.
            </p>
        </button>

        <button type="button" wire:click="setDueWindow('due-soon')"
            class="rounded-2xl border p-5 text-left transition-colors {{ $dueWindow === 'due-soon'
                ? 'border-warning-500 bg-warning-50 dark:border-warning-500/60 dark:bg-warning-500/10'
                : 'border-gray-200 bg-white hover:border-warning-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-warning-500/40' }}">
            <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Due soon</p>
            <p class="mt-1 text-2xl font-semibold text-warning-600 dark:text-orange-400">{{ $dueSoonCount }}</p>
            <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                Due within the next {{ \App\Modules\CRM\Livewire\Tasks\TasksIndex::reminderWindowHours() }} hours
                (the reminder window).
            </p>
        </button>
    </div>

    <x-table.container>
        <x-slot:header>
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative grow sm:max-w-xs">
                    <x-form.input wire:model.live.debounce.300ms="search" type="search"
                        placeholder="Search tasks…" aria-label="Search tasks" />
                </div>

                <div class="w-full sm:w-44">
                    <x-form.select wire:model.live="status" aria-label="Filter by status">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $statusOption)
                            <option value="{{ $statusOption->value }}">{{ $this->statusLabel($statusOption) }}</option>
                        @endforeach
                    </x-form.select>
                </div>

                <div class="w-full sm:w-44">
                    <x-form.select wire:model.live="assigneeFilter" aria-label="Filter by assignee">
                        <option value="">All assignees</option>
                        @foreach ($users as $userOption)
                            <option value="{{ $userOption->id }}">{{ $userOption->display_name }}</option>
                        @endforeach
                    </x-form.select>
                </div>

                <div class="w-full sm:w-28">
                    <x-form.select wire:model.live="perPage" aria-label="Rows per page">
                        <option value="10">10 / page</option>
                        <option value="25">25 / page</option>
                        <option value="50">50 / page</option>
                    </x-form.select>
                </div>

                <div class="grow"></div>

                @can('create', \App\Modules\CRM\Models\Task::class)
                    <x-ui.button wire:click="create">New task</x-ui.button>
                @endcan
            </div>
        </x-slot:header>

        @if ($tasks->isEmpty())
            @if ($search !== '' || $status !== '' || $assigneeFilter !== '' || $dueWindow !== '')
                <x-states.empty title="No tasks match your filters">
                    Try adjusting or clearing the search and filters above.
                </x-states.empty>
            @else
                <x-states.empty title="No tasks yet">
                    Deadlines and follow-ups — link them to a creator or campaign and you'll get a
                    reminder before they're due.
                    <x-slot:action>
                        @can('create', \App\Modules\CRM\Models\Task::class)
                            <x-ui.button size="sm" wire:click="create">New task</x-ui.button>
                        @endcan
                    </x-slot:action>
                </x-states.empty>
            @endif
        @else
            <table class="w-full min-w-[900px]">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <x-table.th field="title" :sort-field="$sortField" :sort-direction="$sortDirection">Title</x-table.th>
                        <x-table.th field="status" :sort-field="$sortField" :sort-direction="$sortDirection">Status</x-table.th>
                        <x-table.th field="due_at" :sort-field="$sortField" :sort-direction="$sortDirection">Due</x-table.th>
                        <x-table.th>Assignee</x-table.th>
                        <x-table.th>Linked to</x-table.th>
                        <x-table.th><span class="sr-only">Actions</span></x-table.th>
                    </tr>
                </thead>
                <tbody wire:loading.class="pointer-events-none opacity-50"
                    class="divide-y divide-gray-100 transition-opacity dark:divide-gray-800">
                    @foreach ($tasks as $task)
                        <tr wire:key="task-{{ $task->id }}">
                            <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ $task->title }}</td>
                            <td class="px-5 py-4">
                                <x-ui.badge :color="$this->statusColor($task->status)">{{ $this->statusLabel($task->status) }}</x-ui.badge>
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                <span class="flex items-center gap-2">
                                    {{ $task->due_at?->format('d.m.Y H:i') ?? '—' }}
                                    @if ($this->dueState($task) === 'overdue')
                                        <x-ui.badge color="error" size="sm">Overdue</x-ui.badge>
                                    @elseif ($this->dueState($task) === 'due-soon')
                                        <x-ui.badge color="warning" size="sm">Due soon</x-ui.badge>
                                    @endif
                                </span>
                                @if ($task->reminder_sent_at !== null)
                                    <span class="mt-0.5 block text-theme-xs text-gray-400">
                                        Reminder fired {{ $task->reminder_sent_at->format('d.m.Y H:i') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $task->assignee?->display_name ?? '—' }}</td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                @if ($task->creator)
                                    <a href="{{ route('crm.creators.show', $task->creator) }}"
                                        class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                        {{ $task->creator->display_name }}
                                    </a>
                                @endif
                                @if ($task->campaign)
                                    <a href="{{ route('crm.campaigns.show', $task->campaign) }}"
                                        class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                        {{ $task->campaign->name }}
                                    </a>
                                @endif
                                @if (! $task->creator && ! $task->campaign)
                                    &mdash;
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    @can('update', $task)
                                        <button type="button" wire:click="edit({{ $task->id }})"
                                            class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Edit</button>
                                    @endcan
                                    @can('delete', $task)
                                        <button type="button" wire:click="confirmDelete({{ $task->id }})"
                                            class="text-sm font-medium text-error-500 hover:text-error-600">Delete</button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <x-slot:footer>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Showing {{ $tasks->count() }} of {{ $tasks->total() }} tasks
                </p>
                {{ $tasks->links() }}
            </div>
        </x-slot:footer>
    </x-table.container>

    @if ($showForm)
        <x-ui.modal :title="$editingTaskId ? 'Edit task' : 'New task'" close-action="cancelForm" max-width="xl">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <x-form.label for="task_title" required>Title</x-form.label>
                    <x-form.input id="task_title" wire:model="task_title" :error="$errors->has('task_title')" />
                    <x-form.error for="task_title" />
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div x-data="{ s: @js($task_status), map: @js($statusDescriptions) }">
                        <x-form.label for="task_status" required>Status</x-form.label>
                        <x-form.select id="task_status" wire:model="task_status" x-on:change="s = $event.target.value"
                            :error="$errors->has('task_status')">
                            @foreach ($statuses as $statusOption)
                                <option value="{{ $statusOption->value }}">{{ $this->statusLabel($statusOption) }}</option>
                            @endforeach
                        </x-form.select>
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400" x-text="map[s] ?? ''"></p>
                        <x-form.error for="task_status" />
                    </div>

                    <div>
                        <x-form.label for="task_assignee_id">Assignee</x-form.label>
                        <x-form.select id="task_assignee_id" wire:model="task_assignee_id"
                            :error="$errors->has('task_assignee_id')">
                            <option value="">Unassigned</option>
                            @foreach ($users as $userOption)
                                <option value="{{ $userOption->id }}">{{ $userOption->display_name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-form.error for="task_assignee_id" />
                    </div>
                </div>

                <div>
                    <x-form.label for="task_due_at">Due at</x-form.label>
                    <x-form.input id="task_due_at" wire:model="task_due_at" type="datetime-local"
                        :error="$errors->has('task_due_at')" />
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                        You’ll get a one-time reminder shortly before the deadline.
                    </p>
                    <x-form.error for="task_due_at" />
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="task_creator_id">Creator (optional)</x-form.label>
                        <x-form.select id="task_creator_id" wire:model="task_creator_id"
                            :error="$errors->has('task_creator_id')">
                            <option value="">No creator link</option>
                            @foreach ($creators as $creatorOption)
                                <option value="{{ $creatorOption->id }}">{{ $creatorOption->display_name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-form.error for="task_creator_id" />
                    </div>

                    <div>
                        <x-form.label for="task_campaign_id">Campaign (optional)</x-form.label>
                        <x-form.select id="task_campaign_id" wire:model="task_campaign_id"
                            :error="$errors->has('task_campaign_id')">
                            <option value="">No campaign link</option>
                            @foreach ($campaigns as $campaignOption)
                                <option value="{{ $campaignOption->id }}">{{ $campaignOption->name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-form.error for="task_campaign_id" />
                    </div>
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelForm" wire:loading.attr="disabled">Cancel</x-ui.button>
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editingTaskId ? 'Save changes' : 'Create task' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($confirmingDeleteId !== null)
        <x-ui.confirm-modal title="Delete task?" confirm-action="delete" cancel-action="cancelDelete"
            confirm-label="Delete task">
            The action is recorded in the audit log.
        </x-ui.confirm-modal>
    @endif
</div>
