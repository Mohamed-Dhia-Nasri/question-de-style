{{--
    Shared roster picker body (CRM UX Stage C, F08). Included inside an
    x-ui.modal by the campaign / seeding creators panels; the host component
    uses the ManagesCreatorRoster trait.

    Parameters: $candidates, $restrictedIds, $blocklistedIds, $brandName.
--}}
@php($selectedCount = count($selectedCreatorIds))

<div class="space-y-4">
    {{-- Toolbar: search + platform filter --}}
    <div class="flex flex-col gap-3 sm:flex-row">
        <div class="flex-1">
            <x-form.input type="search" wire:model.live.debounce.300ms="rosterSearch"
                placeholder="Search by name or handle…" aria-label="Search creators" />
        </div>
        <div class="sm:w-52">
            <x-form.select wire:model.live="rosterPlatform" aria-label="Filter by platform">
                <option value="">All platforms</option>
                @foreach (\App\Shared\Enums\Platform::cases() as $platform)
                    <option value="{{ $platform->value }}">{{ $platform->label() }}</option>
                @endforeach
            </x-form.select>
        </div>
    </div>

    {{-- Candidate list --}}
    @if ($candidates->isEmpty())
        <x-states.empty title="No creators match">Try another search, or create one below.</x-states.empty>
    @else
        <div class="max-h-80 overflow-y-auto rounded-xl border border-gray-200 dark:border-gray-800">
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($candidates->take(50) as $creator)
                    <li wire:key="picker-{{ $creator->id }}">
                        <label class="flex items-start gap-3 px-2 py-2.5">
                            <input type="checkbox" wire:model.live="selectedCreatorIds" value="{{ $creator->id }}"
                                class="mt-0.5 h-5 w-5 cursor-pointer rounded-md border-gray-300 text-brand-500 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900">
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium text-gray-800 dark:text-white/90">{{ $creator->display_name }}</span>
                                @if ($creator->platformAccounts->isNotEmpty())
                                    <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                                        @foreach ($creator->platformAccounts as $account)
                                            {{ $account->platform->label() }}{{ $account->follower_count?->amount !== null ? ' '.\Illuminate\Support\Number::abbreviate((int) $account->follower_count->amount) : '' }}@unless ($loop->last) · @endunless
                                        @endforeach
                                    </span>
                                @endif
                                @if (in_array((int) $creator->id, $restrictedIds, true))
                                    <span class="mt-0.5 block text-xs font-medium text-warning-500">On their no-go list for {{ $brandName }} — will be skipped.</span>
                                @elseif (in_array((int) $creator->id, $blocklistedIds, true))
                                    <span class="mt-0.5 block text-xs text-gray-400">Marked ‘do not contact or book’.</span>
                                @endif
                            </span>
                        </label>
                    </li>
                @endforeach
            </ul>
        </div>
        @if ($candidates->count() > 50)
            <p class="text-xs text-gray-500 dark:text-gray-400">Showing the first 50 — refine your search to narrow the list.</p>
        @endif
    @endif

    {{-- Inline "new creator" --}}
    <div class="border-t border-gray-200 pt-4 dark:border-gray-800">
        @if ($showNewCreatorForm)
            <div class="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                <div>
                    <x-form.label for="new_creator_name" required>Name</x-form.label>
                    <x-form.input id="new_creator_name" wire:model="new_creator_name" placeholder="Creator name"
                        :error="$errors->has('new_creator_name')" />
                    <x-form.error for="new_creator_name" />
                </div>
                <div>
                    <x-form.label for="new_creator_language">Main language</x-form.label>
                    <x-form.input id="new_creator_language" wire:model="new_creator_language" placeholder="e.g. de"
                        :error="$errors->has('new_creator_language')" />
                    <x-form.error for="new_creator_language" />
                </div>
                <div class="flex items-center gap-2">
                    <x-ui.button size="sm" wire:click="createAndAttachCreator" wire:loading.attr="disabled"
                        wire:target="createAndAttachCreator">Add creator</x-ui.button>
                    <x-ui.button size="sm" variant="outline" wire:click="$set('showNewCreatorForm', false)">Cancel</x-ui.button>
                </div>
            </div>
        @else
            <button type="button" wire:click="$set('showNewCreatorForm', true)"
                class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                + New creator
            </button>
        @endif
    </div>
</div>

<x-slot:footer>
    <span class="mr-auto text-sm text-gray-500 dark:text-gray-400">{{ $selectedCount }} selected</span>
    <x-ui.button variant="outline" wire:click="closePicker">Cancel</x-ui.button>
    <x-ui.button wire:click="attachSelected" wire:loading.attr="disabled" wire:target="attachSelected">Add selected</x-ui.button>
</x-slot:footer>
