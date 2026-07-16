<div class="max-w-4xl space-y-4">
    {{-- How it works --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">How estimated reach works</h3>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Reach estimates how many people saw a post. It is calculated from two public numbers —
            the post's views and the creator's followers — and you decide how much each one counts.
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-x-2 gap-y-2 rounded-xl bg-gray-50 p-4 text-sm font-medium text-gray-700 dark:bg-gray-800/50 dark:text-gray-300">
            <span>Estimated reach</span>
            <span class="text-gray-400">=</span>
            <span class="rounded-lg bg-white px-2.5 py-1 shadow-theme-xs dark:bg-gray-900">views <span class="text-gray-400">×</span> % of views counted</span>
            <span class="text-gray-400">+</span>
            <span class="rounded-lg bg-white px-2.5 py-1 shadow-theme-xs dark:bg-gray-900">followers <span class="text-gray-400">×</span> % of followers counted</span>
        </div>

        <x-ui.alert variant="info" title="Reach is always an estimate" class="mt-4">
            Instagram and TikTok never publish how many people actually saw a post or a story, so exact
            reach cannot be known. QDS combines public view counts with a small share of the creator's
            followers to give a consistent, comparable estimate — never an exact head-count.
        </x-ui.alert>
    </div>

    @unless ($live)
        <x-ui.alert variant="warning" title="Reach is currently off">
            No reach settings are active yet, so posts show no reach figure. Save the settings below to turn it on.
        </x-ui.alert>
    @endunless

    {{-- Editor --}}
    <div class="space-y-5 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        @if ($formError)
            <x-ui.alert variant="error">{{ $formError }}</x-ui.alert>
        @endif

        @unless ($canManage)
            <x-ui.alert variant="info">Only administrators can change these settings.</x-ui.alert>
        @endunless

        <div class="max-w-sm">
            <x-form.label for="reach-name">Name</x-form.label>
            <x-form.input id="reach-name" wire:model="name" :disabled="! $canManage" />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Shown in reports next to reach figures.</p>
        </div>

        <div>
            <x-form.toggle label="Customize per platform" wire:model.live="perPlatform" :disabled="! $canManage" />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Instagram, TikTok and YouTube audiences behave differently — you can count views and
                followers differently on each.
            </p>
        </div>

        @php
            $rows = $perPlatform
                ? collect(\App\Modules\Monitoring\Livewire\Reach\ReachSettings::PLATFORM_LABELS)
                    ->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'model' => "platforms.{$key}", 'values' => $platforms[$key]])
                    ->values()
                : collect([['key' => 'ALL', 'label' => 'All platforms', 'model' => 'all', 'values' => $all]]);
        @endphp

        <div class="space-y-3">
            @foreach ($rows as $row)
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800" wire:key="reach-row-{{ $row['key'] }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $row['label'] }}</span>
                        @if (in_array($row['key'], ['INSTAGRAM', 'TIKTOK'], true))
                            <x-ui.badge color="light">always an estimate — story views aren't public</x-ui.badge>
                        @endif
                    </div>

                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <x-form.label for="views-{{ $row['key'] }}">% of views counted</x-form.label>
                            <div class="relative">
                                <x-form.input id="views-{{ $row['key'] }}" type="text" inputmode="decimal" class="pr-10"
                                    wire:model.live.debounce.400ms="{{ $row['model'] }}.views" :disabled="! $canManage" />
                                <span class="pointer-events-none absolute inset-y-0 right-4 flex items-center text-sm text-gray-400">%</span>
                            </div>
                        </div>
                        <div>
                            <x-form.label for="followers-{{ $row['key'] }}">% of followers counted</x-form.label>
                            <div class="relative">
                                <x-form.input id="followers-{{ $row['key'] }}" type="text" inputmode="decimal" class="pr-10"
                                    wire:model.live.debounce.400ms="{{ $row['model'] }}.followers" :disabled="! $canManage" />
                                <span class="pointer-events-none absolute inset-y-0 right-4 flex items-center text-sm text-gray-400">%</span>
                            </div>
                        </div>
                    </div>

                    @if ($example = $this->example($row['values']['views'], $row['values']['followers']))
                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                            Example: {{ $example }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($canManage)
            <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4 dark:border-gray-800">
                <x-ui.button wire:click="save" wire:loading.attr="disabled">
                    {{ $live ? 'Save changes' : 'Save & turn on' }}
                </x-ui.button>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    Applies to posts processed from now on — figures already calculated keep their original settings.
                </span>
            </div>
        @endif
    </div>
</div>
