@php
    use App\Modules\Monitoring\Livewire\Emv\EmvSettings;

    $metricLabels = EmvSettings::METRIC_LABELS;
    $selected = array_keys(array_filter($enabled));
    $symbol = $this->currencySymbol();
@endphp

<div class="max-w-5xl space-y-4">
    {{-- What is EMV --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">How EMV works</h3>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            EMV (Earned Media Value) estimates what a creator's post would have cost you as paid
            advertising. Every interaction you choose below is worth a small amount, and a post's EMV
            is all of them added up.
        </p>

        @if ($preview = $this->formulaPreview())
            <div class="mt-4 rounded-xl bg-gray-50 p-4 text-sm font-medium text-gray-700 dark:bg-gray-800/50 dark:text-gray-300">
                {{ $preview }}
                @if ($example = $this->examplePost())
                    <p class="mt-2 text-xs font-normal text-gray-500 dark:text-gray-400">Example: {{ $example }}</p>
                @endif
            </div>
        @endif
    </div>

    @unless ($live)
        <x-ui.alert variant="warning" title="EMV is currently off">
            No EMV settings are active yet, so posts show no EMV figure. Save the settings below to turn it on.
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

        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <x-form.label for="emv-name">Name</x-form.label>
                <x-form.input id="emv-name" wire:model="name" :disabled="! $canManage" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Shown in reports next to EMV figures.</p>
            </div>
            <div class="sm:max-w-40">
                <x-form.label for="emv-currency">Currency</x-form.label>
                <x-form.select id="emv-currency" wire:model.live="currency" :disabled="! $canManage">
                    {{-- A legacy config may carry a currency outside the list; keep it selectable. --}}
                    @if (! array_key_exists($currency, EmvSettings::CURRENCIES))
                        <option value="{{ $currency }}">{{ $currency }}</option>
                    @endif
                    @foreach (EmvSettings::CURRENCIES as $code => $currencySymbol)
                        <option value="{{ $code }}">{{ $code }} ({{ $currencySymbol }})</option>
                    @endforeach
                </x-form.select>
            </div>
        </div>

        <div>
            <span class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                Which interactions earn value?
            </span>
            <div class="flex flex-wrap gap-2">
                @foreach ($metricLabels as $metric => $label)
                    <button type="button" wire:click="toggleMetric('{{ $metric }}')" wire:key="chip-{{ $metric }}"
                        aria-pressed="{{ $enabled[$metric] ? 'true' : 'false' }}" @disabled(! $canManage)
                        class="inline-flex h-10 items-center gap-1.5 rounded-full border px-4 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60 {{ $enabled[$metric]
                            ? 'border-brand-500 bg-brand-500 text-white'
                            : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800' }}">
                        @if ($enabled[$metric])
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        @endif
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Tap to add or remove an interaction from the formula.</p>
        </div>

        @if ($selected !== [])
            <div>
                <span class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    What is each interaction worth?
                </span>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($selected as $metric)
                        <div wire:key="rate-{{ $metric }}">
                            <x-form.label for="rate-{{ $metric }}">{{ EmvSettings::UNIT_LABELS[$metric] }} is worth</x-form.label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-sm text-gray-400">{{ $symbol }}</span>
                                <x-form.input id="rate-{{ $metric }}" type="text" inputmode="decimal" class="pl-9"
                                    wire:model.live.debounce.400ms="rates.{{ $metric }}"
                                    placeholder="e.g. {{ EmvSettings::RATE_HINTS[$metric] }}" :disabled="! $canManage" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Per-platform fine-tuning --}}
            <div>
                <x-form.toggle label="Fine-tune per platform" wire:model.live="byPlatform" :disabled="! $canManage" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Give an interaction a different value on Instagram, TikTok or YouTube. Empty boxes use the base value.
                </p>

                @if ($byPlatform)
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                                    <th class="py-2 pr-4 font-medium">Platform</th>
                                    @foreach ($selected as $metric)
                                        <th class="px-2 py-2 font-medium" wire:key="ph-{{ $metric }}">{{ $metricLabels[$metric] }} ({{ $symbol }})</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (EmvSettings::PLATFORM_LABELS as $platform => $platformLabel)
                                    <tr wire:key="platform-row-{{ $platform }}" class="border-t border-gray-100 dark:border-gray-800">
                                        <td class="py-2 pr-4 font-medium text-gray-700 dark:text-gray-300">{{ $platformLabel }}</td>
                                        @foreach ($selected as $metric)
                                            <td class="px-2 py-2" wire:key="pc-{{ $platform }}-{{ $metric }}">
                                                <input type="text" inputmode="decimal"
                                                    aria-label="{{ $platformLabel }} — {{ $metricLabels[$metric] }} value in {{ $currency }}"
                                                    class="h-10 w-24 rounded-lg border border-gray-300 bg-transparent px-2 text-center text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30"
                                                    wire:model="platformRates.{{ $platform }}.{{ $metric }}"
                                                    placeholder="{{ $rates[$metric] !== '' ? $rates[$metric] : '—' }}"
                                                    @disabled(! $canManage) />
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Per-format fine-tuning --}}
            <div>
                <x-form.toggle label="Fine-tune per content format" wire:model.live="byFormat" :disabled="! $canManage" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Give an interaction a different value on Reels, videos, image posts… Empty boxes use the base value.
                </p>

                @if ($byFormat)
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                                    <th class="py-2 pr-4 font-medium">Format</th>
                                    @foreach ($selected as $metric)
                                        <th class="px-2 py-2 font-medium" wire:key="fh-{{ $metric }}">{{ $metricLabels[$metric] }} ({{ $symbol }})</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (EmvSettings::FORMAT_LABELS as $format => $formatLabel)
                                    <tr wire:key="format-row-{{ $format }}" class="border-t border-gray-100 dark:border-gray-800">
                                        <td class="py-2 pr-4 font-medium text-gray-700 dark:text-gray-300">{{ $formatLabel }}</td>
                                        @foreach ($selected as $metric)
                                            <td class="px-2 py-2" wire:key="fc-{{ $format }}-{{ $metric }}">
                                                <input type="text" inputmode="decimal"
                                                    aria-label="{{ $formatLabel }} — {{ $metricLabels[$metric] }} value in {{ $currency }}"
                                                    class="h-10 w-24 rounded-lg border border-gray-300 bg-transparent px-2 text-center text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:ring-3 focus:ring-brand-500/10 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30"
                                                    wire:model="formatRates.{{ $format }}.{{ $metric }}"
                                                    placeholder="{{ $rates[$metric] !== '' ? $rates[$metric] : '—' }}"
                                                    @disabled(! $canManage) />
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif

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

    {{-- Campaign math --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">How QDS uses EMV in campaigns</h3>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-800/50">
                <p class="text-sm font-medium text-gray-800 dark:text-white/90">Total campaign EMV</p>
                <p class="mt-1 font-mono text-xs text-gray-600 dark:text-gray-400">total EMV = sum of every organic post's EMV</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Everything the campaign earned, added up.</p>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-800/50">
                <p class="text-sm font-medium text-gray-800 dark:text-white/90">ROI</p>
                <p class="mt-1 font-mono text-xs text-gray-600 dark:text-gray-400">ROI = total EMV ÷ campaign cost</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">How much value the campaign earned per unit spent.</p>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-800/50">
                <p class="text-sm font-medium text-gray-800 dark:text-white/90">Organic rate</p>
                <p class="mt-1 font-mono text-xs text-gray-600 dark:text-gray-400">organic rate = organic posts ÷ products sent</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">How many of the products you sent turned into posts.</p>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-800/50">
                <p class="text-sm font-medium text-gray-800 dark:text-white/90">Cost per mention</p>
                <p class="mt-1 font-mono text-xs text-gray-600 dark:text-gray-400">cost per mention = campaign cost ÷ organic posts</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">What each organic post effectively cost you.</p>
            </div>
        </div>
    </div>
</div>
