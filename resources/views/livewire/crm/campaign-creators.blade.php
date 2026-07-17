<div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4 dark:border-gray-800">
        <div>
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Participating creators</h3>
            <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                The creators taking part in this campaign. Creators who refuse this brand are
                skipped automatically.
            </p>
        </div>

        @can('update', $campaign)
            <x-ui.button size="sm" wire:click="openPicker">Add creators</x-ui.button>
        @endcan
    </div>

    @if ($attached->isEmpty())
        <x-states.empty title="No creators on this campaign yet">
            Add the creators taking part in this campaign. Creators who refuse this brand are
            skipped automatically.
        </x-states.empty>
    @else
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($attached as $creator)
                <li wire:key="campaign-creator-{{ $creator->id }}" class="flex items-center justify-between px-6 py-3">
                    <a href="{{ route('crm.creators.show', $creator) }}"
                        class="text-sm font-medium text-gray-800 hover:text-brand-500 dark:text-white/90 dark:hover:text-brand-400">
                        {{ $creator->display_name }}
                    </a>
                    @can('update', $campaign)
                        <button type="button" wire:click="confirmDetach({{ $creator->id }})"
                            class="text-sm font-medium text-error-500 hover:text-error-600">
                            Remove
                        </button>
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif

    @if ($confirmingDetachId !== null)
        <x-ui.confirm-modal title="Remove creator from campaign?" confirm-action="detach" cancel-action="cancelDetach"
            confirm-label="Remove creator">
            This removes the participation record. The action is recorded in the audit log.
        </x-ui.confirm-modal>
    @endif

    @if ($showPicker)
        <x-ui.modal :title="'Add creators'" close-action="closePicker" max-width="2xl">
            @include('livewire.crm.partials.roster-picker', [
                'candidates' => $candidates,
                'restrictedIds' => $restrictedIds,
                'blocklistedIds' => $blocklistedIds,
                'brandName' => $campaign->brand->name,
            ])
        </x-ui.modal>
    @endif
</div>
