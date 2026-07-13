<div class="space-y-4">
    <div class="flex items-center justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Reach formula: <span class="font-mono">round(view_weight × views + follower_weight × followers)</span>,
            versioned per tenant. Estimated reach stays <span class="font-medium">unavailable</span> until a
            configuration is activated; it is always an ESTIMATED figure and never a raw view count (DEF-003).
        </p>

        @can('create', App\Modules\Monitoring\Models\ReachConfiguration::class)
            <x-ui.button size="sm" wire:click="create">New configuration</x-ui.button>
        @endcan
    </div>

    @if ($showForm)
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            @if ($formError)
                <x-ui.alert variant="error" class="mb-3">{{ $formError }}</x-ui.alert>
            @endif

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <x-form.label for="name" required>Name</x-form.label>
                    <x-form.input id="name" wire:model="name" placeholder="QDS Estimated Reach 2026" />
                </div>
                <div>
                    <x-form.label for="method" required>Method label</x-form.label>
                    <x-form.input id="method" wire:model="method" placeholder="qds-estimated-reach" />
                </div>
                <div>
                    <x-form.label for="formulaVersion" required>Formula version</x-form.label>
                    <x-form.input id="formulaVersion" wire:model="formulaVersion" placeholder="reach-2026.1" />
                </div>
                <div>
                    <x-form.label for="viewWeight" required>View weight (α)</x-form.label>
                    <x-form.input id="viewWeight" type="number" step="0.01" wire:model="viewWeight" />
                </div>
                <div>
                    <x-form.label for="followerWeight" required>Follower weight (β)</x-form.label>
                    <x-form.input id="followerWeight" type="number" step="0.01" wire:model="followerWeight" />
                </div>
                <div>
                    <x-form.label for="effectiveFrom" required>Effective from</x-form.label>
                    <x-form.input id="effectiveFrom" type="date" wire:model="effectiveFrom" />
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <x-form.label for="platformsJson">Per-platform overrides (JSON, optional)</x-form.label>
                    <x-form.textarea id="platformsJson" rows="3" wire:model="platformsJson"
                        placeholder='{"INSTAGRAM": {"view_weight": 0.8, "follower_weight": 0.05}}' />
                    <p class="mt-1 text-xs text-gray-500">
                        Optional, keyed by platform. Each platform's effective follower weight must stay above zero —
                        reach always retains a follower signal, never a raw view count.
                    </p>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <x-form.label for="notes">Notes &amp; assumptions</x-form.label>
                    <x-form.textarea id="notes" rows="2" wire:model="notes" />
                </div>
            </div>

            <div class="mt-4 flex gap-2">
                <x-ui.button size="sm" wire:click="save">Create draft</x-ui.button>
                <x-ui.button size="sm" variant="outline" wire:click="$set('showForm', false)">Cancel</x-ui.button>
            </div>
        </div>
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @if ($configurations->isEmpty())
            <x-states.empty title="No reach configuration yet">
                Estimated reach is unavailable until an authorized user creates and activates a valid configuration
                (REQ-M1-006). It is never silently zero.
            </x-states.empty>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($configurations as $configuration)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-4" wire:key="reach-{{ $configuration->id }}">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{ $configuration->name }}</span>
                                <x-ui.badge :color="match ($configuration->status->value) {
                                    'ACTIVE' => 'success',
                                    'DRAFT' => 'info',
                                    'INACTIVE' => 'warning',
                                    default => 'light',
                                }">{{ $configuration->status->value }}</x-ui.badge>
                                <span class="text-xs text-gray-500">
                                    {{ $configuration->method }} · {{ $configuration->formula_version }} ·
                                    effective {{ $configuration->effective_from->toDateString() }}
                                </span>
                            </div>
                            <div class="mt-1 flex flex-wrap gap-1 text-xs text-gray-500 dark:text-gray-400">
                                <span class="rounded bg-gray-50 px-1.5 py-0.5 font-mono dark:bg-gray-800">
                                    α={{ $configuration->params['view_weight'] ?? '—' }},
                                    β={{ $configuration->params['follower_weight'] ?? '—' }}
                                </span>
                                @if ($configuration->activated_at)
                                    <span class="rounded bg-gray-50 px-1.5 py-0.5 dark:bg-gray-800">
                                        activated {{ $configuration->activated_at->toDayDateTimeString() }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        @can('update', $configuration)
                            <div class="flex shrink-0 gap-2">
                                @if ($configuration->status->value === 'DRAFT' || $configuration->status->value === 'INACTIVE')
                                    <x-ui.button size="sm" wire:click="activate({{ $configuration->id }})">Activate</x-ui.button>
                                @endif
                                @if ($configuration->status->value === 'ACTIVE')
                                    <x-ui.button size="sm" variant="outline" wire:click="deactivate({{ $configuration->id }})">Deactivate</x-ui.button>
                                @endif
                                @if ($configuration->status->value !== 'ACTIVE' && $configuration->status->value !== 'ARCHIVED')
                                    <x-ui.button size="sm" variant="outline" wire:click="archive({{ $configuration->id }})">Archive</x-ui.button>
                                @endif
                            </div>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
