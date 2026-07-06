<div class="space-y-4">
    <div class="flex items-center justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            EMV model: <span class="font-mono">Σ (metric × rate)</span> over content, with a transparent,
            versioned rate card. EMV stays <span class="font-medium">unavailable</span> until a configuration
            is activated; every report disclosures the model, rates, and input tiers (AC-M1-011).
        </p>

        @can('create', App\Modules\Monitoring\Models\EmvConfiguration::class)
            <x-ui.button size="sm" wire:click="create">New configuration</x-ui.button>
        @endcan
    </div>

    @if ($showForm)
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            @if ($formError)
                <x-ui.alert type="error" class="mb-3">{{ $formError }}</x-ui.alert>
            @endif

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <x-form.label for="name" required>Name</x-form.label>
                    <x-form.input id="name" wire:model="name" placeholder="EMV model 2026 H2" />
                </div>
                <div>
                    <x-form.label for="formulaVersion" required>Formula version</x-form.label>
                    <x-form.input id="formulaVersion" wire:model="formulaVersion" placeholder="formula-v1" />
                </div>
                <div>
                    <x-form.label for="rateCardVersion" required>Rate-card version</x-form.label>
                    <x-form.input id="rateCardVersion" wire:model="rateCardVersion" placeholder="rates-2026-07" />
                </div>
                <div>
                    <x-form.label for="currency" required>Currency (ISO)</x-form.label>
                    <x-form.input id="currency" wire:model="currency" placeholder="EUR" />
                </div>
                <div>
                    <x-form.label for="effectiveFrom" required>Effective from</x-form.label>
                    <x-form.input id="effectiveFrom" type="date" wire:model="effectiveFrom" />
                </div>
                <div>
                    <x-form.label for="metrics" required>Formula metrics (comma-separated)</x-form.label>
                    <x-form.input id="metrics" wire:model="metrics" placeholder="views, likes, comments" />
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <x-form.label for="ratesJson" required>Rate card (JSON)</x-form.label>
                    <x-form.textarea id="ratesJson" rows="6" wire:model="ratesJson"
                        placeholder='{"default": {"views": 0.01, "likes": 0.05, "comments": 0.2}, "platforms": {"INSTAGRAM": {"views": 0.012}}}' />
                    <p class="mt-1 text-xs text-gray-500">
                        No built-in defaults exist — every formula metric needs a rate in
                        <span class="font-mono">default</span>. Optional overrides:
                        <span class="font-mono">platforms</span>, <span class="font-mono">content_types</span>.
                        Country variations are not supported in v1 (content carries no geo attribution).
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
            <x-states.empty title="No EMV configuration yet">
                EMV is unavailable until an authorized user creates and activates a valid configuration
                (REQ-M1-011). It is never silently zero.
            </x-states.empty>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($configurations as $configuration)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-4" wire:key="emv-{{ $configuration->id }}">
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
                                    {{ $configuration->formula_version }} · {{ $configuration->rate_card_version }} ·
                                    {{ $configuration->currency }} · effective {{ $configuration->effective_from->toDateString() }}
                                </span>
                            </div>
                            <div class="mt-1 flex flex-wrap gap-1 text-xs text-gray-500 dark:text-gray-400">
                                <span class="rounded bg-gray-50 px-1.5 py-0.5 font-mono dark:bg-gray-800">
                                    Σ over: {{ implode(', ', $configuration->formula['metrics'] ?? []) }}
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
