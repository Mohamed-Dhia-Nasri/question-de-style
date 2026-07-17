<div class="space-y-5">
    {{-- How it works --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">How these settings work</h3>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            These four values control how monitoring links posts to gifts, how trends are
            calculated, and how long collected files and notes are kept. They apply to your
            whole workspace. Every save is recorded, and changes apply from now on — nothing
            already calculated is changed.
        </p>
    </div>

    <div class="space-y-5 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        @if ($formError)
            <x-ui.alert variant="error">{{ $formError }}</x-ui.alert>
        @endif

        @unless ($canManage)
            <x-ui.alert variant="info">Only administrators can change these settings.</x-ui.alert>
        @endunless

        {{-- Gift link window --}}
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
            <span class="text-sm font-semibold text-gray-800 dark:text-white/90">Gift link window</span>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                After you send a product, posts published within this many days can be linked to
                that gift. Example: product delivered on 1 June with a 60-day window — posts up
                to 31 July can count for it.
            </p>
            <div class="mt-3 max-w-45">
                <x-form.label for="shipment-days">Days after delivery</x-form.label>
                <x-form.input id="shipment-days" type="text" inputmode="numeric"
                    wire:model.live.debounce.400ms="shipmentDays" :disabled="! $canManage" />
            </div>
        </div>

        {{-- Engagement trend window --}}
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
            <span class="text-sm font-semibold text-gray-800 dark:text-white/90">Engagement trend window</span>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                The trend on a creator's page compares the average likes + comments per post of
                the last period with the period before. Example: with 30 days, it compares the
                last 30 days to the 30 days before that.
            </p>
            <div class="mt-3 max-w-45">
                <x-form.label for="trend-days">Days per period</x-form.label>
                <x-form.input id="trend-days" type="text" inputmode="numeric"
                    wire:model.live.debounce.400ms="trendDays" :disabled="! $canManage" />
            </div>
        </div>

        {{-- Story keep time --}}
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
            <span class="text-sm font-semibold text-gray-800 dark:text-white/90">Story keep time</span>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Stories disappear from the platform after 24 hours, so QDS saves a copy of the
                photo or video. Old files can be cleaned up automatically — the story's numbers
                and text are always kept, only the file is deleted. Deletion is permanent.
            </p>
            <div class="mt-3">
                <x-form.toggle label="Delete old story files automatically" wire:model.live="storyCleanupEnabled" :disabled="! $canManage" />
            </div>
            @if ($storyCleanupEnabled)
                <div class="mt-3 max-w-45">
                    <x-form.label for="story-days">Keep story files for (days)</x-form.label>
                    <x-form.input id="story-days" type="text" inputmode="numeric"
                        wire:model.live.debounce.400ms="storyDays" :disabled="! $canManage" />
                </div>
            @else
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Story files are kept forever.</p>
            @endif
        </div>

        {{-- Message history keep time --}}
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
            <span class="text-sm font-semibold text-gray-800 dark:text-white/90">Message history keep time</span>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Notes about calls, emails and messages with creators are the longest-lived
                personal data in the CRM. For privacy rules you can delete old entries
                automatically. Example: 365 keeps one year of history.
            </p>
            <div class="mt-3">
                <x-form.toggle label="Delete old message history automatically" wire:model.live="commsCleanupEnabled" :disabled="! $canManage" />
            </div>
            @if ($commsCleanupEnabled)
                <div class="mt-3 max-w-45">
                    <x-form.label for="comms-days">Keep message history for (days)</x-form.label>
                    <x-form.input id="comms-days" type="text" inputmode="numeric"
                        wire:model.live.debounce.400ms="commsDays" :disabled="! $canManage" />
                </div>
            @else
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Message history is kept forever.</p>
            @endif
        </div>

        @if ($canManage)
            <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4 dark:border-gray-800">
                <x-ui.button wire:click="save" wire:loading.attr="disabled">Save changes</x-ui.button>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    Applies from now on — the gift window and trend use the new values on the next
                    calculation, and file cleanup runs nightly.
                </span>
            </div>
        @endif
    </div>
</div>
