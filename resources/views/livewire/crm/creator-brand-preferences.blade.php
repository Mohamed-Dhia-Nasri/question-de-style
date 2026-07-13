<div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4 dark:border-gray-800">
        <div>
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Brand preferences</h3>
            <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                Preferred and restricted brand names (REQ-M3-003) — plain name lists, enforced on campaign joins from Step 3.
            </p>
        </div>
        @can('create', \App\Modules\CRM\Models\BrandPreference::class)
            <x-ui.button size="sm" wire:click="add">Add preference</x-ui.button>
        @endcan
    </div>

    @if ($preferences->isEmpty())
        <x-states.empty title="No brand preferences recorded">
            Record which brands this creator prefers — and which they will not work with.
        </x-states.empty>
    @else
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($preferences as $preference)
                <div wire:key="preference-{{ $preference->id }}" class="flex flex-wrap items-start justify-between gap-4 px-6 py-4">
                    <div class="grid grow grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <p class="mb-1.5 text-theme-xs font-medium uppercase text-gray-400">Preferred</p>
                            @if (empty($preference->preferred_brands))
                                <span class="text-sm text-gray-400">&mdash;</span>
                            @else
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($preference->preferred_brands as $brand)
                                        <x-ui.badge color="success">{{ $brand }}</x-ui.badge>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div>
                            <p class="mb-1.5 text-theme-xs font-medium uppercase text-gray-400">Restricted</p>
                            @if (empty($preference->restricted_brands))
                                <span class="text-sm text-gray-400">&mdash;</span>
                            @else
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($preference->restricted_brands as $brand)
                                        <x-ui.badge color="error">{{ $brand }}</x-ui.badge>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div>
                            <p class="mb-1.5 text-theme-xs font-medium uppercase text-gray-400">Notes</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $preference->notes ?: '—' }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        @can('update', $preference)
                            <button type="button" wire:click="edit({{ $preference->id }})"
                                class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                Edit
                            </button>
                        @endcan
                        @can('delete', $preference)
                            <button type="button" wire:click="confirmDelete({{ $preference->id }})"
                                class="text-sm font-medium text-error-500 hover:text-error-600">
                                Delete
                            </button>
                        @endcan
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Create / edit modal --}}
    @if ($showForm)
        <x-ui.modal :title="$editingPreferenceId ? 'Edit brand preference' : 'Add brand preference'" close-action="cancelForm">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <x-form.label for="preference_preferred">Preferred brands</x-form.label>
                    <x-form.textarea id="preference_preferred" wire:model="preference_preferred" rows="3"
                        :error="$errors->has('preference_preferred')" placeholder="One brand name per line" />
                    <x-form.error for="preference_preferred" />
                </div>

                <div>
                    <x-form.label for="preference_restricted">Restricted brands</x-form.label>
                    <x-form.textarea id="preference_restricted" wire:model="preference_restricted" rows="3"
                        :error="$errors->has('preference_restricted')" placeholder="One brand name per line — brands this creator will not work with" />
                    <x-form.error for="preference_restricted" />
                </div>

                <div>
                    <x-form.label for="preference_notes">Notes</x-form.label>
                    <x-form.textarea id="preference_notes" wire:model="preference_notes" rows="2"
                        :error="$errors->has('preference_notes')" placeholder="Exclusivities, context…" />
                    <x-form.error for="preference_notes" />
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelForm" wire:loading.attr="disabled">
                    Cancel
                </x-ui.button>
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editingPreferenceId ? 'Save changes' : 'Add preference' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Delete confirmation --}}
    @if ($confirmingDeleteId !== null)
        <x-ui.confirm-modal title="Delete brand preference?" confirm-action="delete" cancel-action="cancelDelete"
            confirm-label="Delete preference">
            This removes the preference/restriction record. Restrictions no longer apply to future
            campaign or seeding assignments.
        </x-ui.confirm-modal>
    @endif
</div>
