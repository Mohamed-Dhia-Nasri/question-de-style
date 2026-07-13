<div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4 dark:border-gray-800">
        <div>
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Tasks</h3>
            <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                Deadlines and follow-ups linked to this record — the full task board lives under
                <a href="{{ route('crm.tasks.index') }}"
                    class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">CRM → Tasks</a>.
            </p>
        </div>
    </div>

    @can('create', \App\Modules\CRM\Models\Task::class)
        <form wire:submit="quickAdd"
            class="flex flex-wrap items-start gap-3 border-b border-gray-200 px-6 py-4 dark:border-gray-800">
            <div class="grow sm:max-w-xs">
                <x-form.input wire:model="quick_title" placeholder="New task title…" aria-label="New task title"
                    :error="$errors->has('quick_title')" />
                <x-form.error for="quick_title" />
            </div>
            <div class="w-full sm:w-56">
                <x-form.input wire:model="quick_due_at" type="datetime-local" aria-label="Due at"
                    :error="$errors->has('quick_due_at')" />
                <x-form.error for="quick_due_at" />
            </div>
            <x-ui.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="quickAdd">
                <span wire:loading.remove wire:target="quickAdd">Add task</span>
                <span wire:loading wire:target="quickAdd">Adding…</span>
            </x-ui.button>
        </form>
    @endcan

    @if ($tasks->isEmpty())
        <x-states.empty title="No tasks yet">
            Add a follow-up or deadline — it stays linked to this record.
        </x-states.empty>
    @else
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($tasks as $task)
                <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3" wire:key="task-{{ $task->id }}">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ $task->title }}</p>
                        <p class="mt-0.5 flex items-center gap-2 text-theme-xs text-gray-500 dark:text-gray-400">
                            <span>Due {{ $task->due_at?->format('d.m.Y H:i') ?? '—' }}</span>
                            @if ($this->dueState($task) === 'overdue')
                                <x-ui.badge color="error" size="sm">Overdue</x-ui.badge>
                            @elseif ($this->dueState($task) === 'due-soon')
                                <x-ui.badge color="warning" size="sm">Due soon</x-ui.badge>
                            @endif
                            {{-- The fired reminder is visible wherever the task is (GAP-3). --}}
                            @if ($task->reminder_sent_at !== null)
                                <x-ui.badge color="info" size="sm">Reminded</x-ui.badge>
                            @endif
                            @if ($task->assignee)
                                <span>· {{ $task->assignee->display_name }}</span>
                            @endif
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        @can('update', $task)
                            {{-- Status flip: closed ENUM-TaskStatus set, audited from→to. --}}
                            <div class="w-40">
                                <x-form.select wire:change="setStatus({{ $task->id }}, $event.target.value)"
                                    aria-label="Task status" class="!h-9">
                                    @foreach ($statuses as $statusOption)
                                        <option value="{{ $statusOption->value }}" @selected($task->status === $statusOption)>
                                            {{ $this->statusLabel($statusOption) }}
                                        </option>
                                    @endforeach
                                </x-form.select>
                            </div>
                        @else
                            <x-ui.badge :color="$this->statusColor($task->status)">{{ $this->statusLabel($task->status) }}</x-ui.badge>
                        @endcan
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
