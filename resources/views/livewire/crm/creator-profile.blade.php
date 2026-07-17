<div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-800">
        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Identity</h3>
        <div class="flex items-center gap-3">
            @can('create', \App\Modules\Monitoring\Models\MonitoredSubject::class)
                <x-ui.button variant="outline" type="button" wire:click="runMonitoringNow"
                    wire:loading.attr="disabled" wire:target="runMonitoringNow"
                    title="Poll this creator's accounts now instead of waiting for the scheduled monitoring cycle.">
                    <span wire:loading.remove wire:target="runMonitoringNow">Run monitoring now</span>
                    <span wire:loading wire:target="runMonitoringNow">Starting…</span>
                </x-ui.button>
            @endcan
        </div>
    </div>

    <form wire:submit="save" class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-3">
        <div>
            <x-form.label for="profile_display_name" required>Display name</x-form.label>
            <x-form.input id="profile_display_name" wire:model="display_name"
                :error="$errors->has('display_name')" />
            <x-form.error for="display_name" />
        </div>

        <div>
            <x-form.label for="profile_primary_language">Primary language</x-form.label>
            <x-form.input id="profile_primary_language" wire:model="primary_language"
                :error="$errors->has('primary_language')" placeholder="e.g. de" />
            <x-form.error for="primary_language" />
        </div>

        <div x-data="{ s: @js($relationship_status), map: @js($statusDescriptions) }">
            <x-form.label for="profile_relationship_status">Relationship status</x-form.label>
            <x-form.select id="profile_relationship_status" wire:model="relationship_status"
                x-on:change="s = $event.target.value" :error="$errors->has('relationship_status')">
                <option value="">— none —</option>
                @foreach ($statuses as $statusOption)
                    <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                @endforeach
            </x-form.select>
            <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400" x-text="map[s] ?? ''"></p>
            <x-form.error for="relationship_status" />
        </div>

        @can('update', $creator)
            <div class="flex items-center gap-3 sm:col-span-3">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save identity</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
                <x-ui.button variant="outline" type="button" wire:click="resetToCurrent" wire:loading.attr="disabled">
                    Reset
                </x-ui.button>
            </div>
        @endcan
    </form>
</div>
