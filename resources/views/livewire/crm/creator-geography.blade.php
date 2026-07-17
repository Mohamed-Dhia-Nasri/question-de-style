<div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="flex items-center justify-between">
        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Geography</h3>
        @if ($current !== null)
            {{-- Operator assertion, never an observed fact (DP-003). --}}
            <x-ui.badge color="info" size="sm">Set by your team · {{ $current->assessment->verificationStatus->label() }}</x-ui.badge>
        @endif
    </div>

    @if ($current === null)
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            No geography assigned.
            <x-states.unavailable reason="No location set yet — pick the country below." />
        </p>
    @else
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            {{ \App\Shared\Enums\Country::labelFor($current->country_code) }}@if ($current->region) · {{ $current->region }}@endif @if ($current->city) · {{ $current->city }}@endif
        </p>
    @endif

    @can('update', $creator)
        <form wire:submit="save" class="mt-4 flex flex-wrap items-end gap-3">
            <div class="w-full sm:w-44">
                <x-form.label for="geo_country">Country</x-form.label>
                <x-form.select id="geo_country" wire:model="geo_country">
                    <option value="">— none —</option>
                    @foreach ($countries as $country)
                        <option value="{{ $country->value }}">{{ $country->label() }}</option>
                    @endforeach
                </x-form.select>
                <x-form.error for="geo_country" />
            </div>
            <div class="w-full sm:w-44">
                <x-form.label for="geo_region">Region</x-form.label>
                <x-form.input id="geo_region" wire:model="geo_region" placeholder="Bavaria"
                    :error="$errors->has('geo_region')" />
                <x-form.error for="geo_region" />
            </div>
            <div class="w-full sm:w-44">
                <x-form.label for="geo_city">City</x-form.label>
                <x-form.input id="geo_city" wire:model="geo_city" placeholder="Munich"
                    :error="$errors->has('geo_city')" />
                <x-form.error for="geo_city" />
            </div>
            <x-ui.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save geography</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-ui.button>
        </form>
        <p class="mt-2 text-theme-xs text-gray-400 dark:text-gray-500">
            Clearing the country removes the location. Dashboards pick the change up on the
            next data refresh.
        </p>
    @endcan
</div>
