<div class="mx-auto max-w-3xl">
    @if ($finished)
        {{-- Done screen — never a redirect, so skipped names survive. --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-start gap-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-success-50 text-success-600 dark:bg-success-500/10">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Campaign created</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        It starts as a draft. Open it to add spend, change the status, or keep building the roster.
                    </p>

                    <div class="mt-5 flex flex-wrap gap-3">
                        @if ($createdCampaignId !== null)
                            <a href="{{ route('crm.campaigns.show', $createdCampaignId) }}"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-brand-500 px-5 py-3 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600">
                                Open the campaign →
                            </a>
                        @endif
                        @if ($createdRunId !== null)
                            <a href="{{ route('crm.seeding.show', $createdRunId) }}"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-white px-5 py-3 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 transition hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                Open the seeding run →
                            </a>
                        @endif
                        <a href="{{ route('crm.campaigns.index') }}"
                            class="inline-flex items-center gap-1.5 rounded-lg px-5 py-3 text-sm font-medium text-gray-500 transition hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">
                            Back to all campaigns
                        </a>
                    </div>

                    @if ($skippedCreators !== [])
                        <div class="mt-6 rounded-xl border border-warning-500/40 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-500/10">
                            <p class="text-sm font-medium text-warning-600 dark:text-warning-400">
                                These creators were not added:
                            </p>
                            <ul class="mt-1.5 list-inside list-disc text-sm text-gray-600 dark:text-gray-300">
                                @foreach ($skippedCreators as $name)
                                    <li>{{ $name }}</li>
                                @endforeach
                            </ul>
                            <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                                Everyone else was added. You can revisit these on the campaign page.
                            </p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @else
        {{-- Progress rail --}}
        @php
            $rail = [
                1 => '1 · Client & brand',
                2 => '2 · Campaign',
                3 => '3 · Seeding run',
                4 => '4 · Creators',
                5 => '5 · Review',
            ];
        @endphp
        <ol class="mb-6 flex flex-wrap gap-2">
            @foreach ($rail as $num => $label)
                <li class="flex items-center gap-2 rounded-lg border px-3 py-2 text-xs font-medium
                    @if ($num === $step) border-brand-500 bg-brand-50 text-brand-600 dark:border-brand-500/50 dark:bg-brand-500/10 dark:text-brand-400
                    @elseif ($num < $step) border-success-500/40 text-success-600 dark:border-success-500/30
                    @else border-gray-200 text-gray-400 dark:border-gray-800 dark:text-gray-500 @endif">
                    @if ($num < $step)
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    @endif
                    {{ $label }}
                </li>
            @endforeach
        </ol>

        <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            {{-- ============ 1: Client & brand ============ --}}
            @if ($step === 1)
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Who is this campaign for?</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Pick the client and brand, or create them here — whichever you need.
                </p>

                <div class="mt-6 space-y-6">
                    {{-- Client --}}
                    <fieldset>
                        <legend class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-400">Client</legend>
                        <div class="flex flex-wrap gap-4">
                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <input type="radio" wire:model.live="client_mode" value="existing"
                                    class="h-4 w-4 border-gray-300 text-brand-500 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900">
                                Use an existing client
                            </label>
                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <input type="radio" wire:model.live="client_mode" value="new"
                                    class="h-4 w-4 border-gray-300 text-brand-500 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900">
                                Create a new client
                            </label>
                        </div>

                        @if ($client_mode === 'existing')
                            <div class="mt-3">
                                <x-form.select wire:model.live="wizard_client_id" :error="$errors->has('wizard_client_id')"
                                    aria-label="Client">
                                    <option value="">Select a client…</option>
                                    @foreach ($clients as $clientOption)
                                        <option value="{{ $clientOption->id }}">{{ $clientOption->name }}</option>
                                    @endforeach
                                </x-form.select>
                                <x-form.error for="wizard_client_id" />
                            </div>
                        @else
                            <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <x-form.label for="new_client_name" required>Client name</x-form.label>
                                    <x-form.input id="new_client_name" wire:model="new_client_name"
                                        :error="$errors->has('new_client_name')" />
                                    <x-form.error for="new_client_name" />
                                </div>
                                <div>
                                    <x-form.label for="new_client_country">Country</x-form.label>
                                    <x-form.select id="new_client_country" wire:model="new_client_country"
                                        :error="$errors->has('new_client_country')">
                                        <option value="">No country</option>
                                        @foreach ($countries as $country)
                                            <option value="{{ $country->value }}">{{ $country->label() }}</option>
                                        @endforeach
                                    </x-form.select>
                                    <x-form.error for="new_client_country" />
                                </div>
                            </div>
                        @endif
                    </fieldset>

                    {{-- Brand --}}
                    <fieldset class="border-t border-gray-200 pt-6 dark:border-gray-800">
                        <legend class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-400">Brand</legend>
                        @if ($client_mode === 'existing')
                            <div class="flex flex-wrap gap-4">
                                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                    <input type="radio" wire:model.live="brand_mode" value="existing"
                                        class="h-4 w-4 border-gray-300 text-brand-500 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900">
                                    Use an existing brand
                                </label>
                                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                    <input type="radio" wire:model.live="brand_mode" value="new"
                                        class="h-4 w-4 border-gray-300 text-brand-500 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900">
                                    Create a new brand
                                </label>
                            </div>

                            @if ($brand_mode === 'existing')
                                <div class="mt-3">
                                    <x-form.select wire:model.live="wizard_brand_id" :error="$errors->has('wizard_brand_id')"
                                        aria-label="Brand">
                                        <option value="">
                                            {{ $wizard_client_id === '' ? 'Choose a client first…' : 'Select a brand…' }}
                                        </option>
                                        @foreach ($brands as $brandOption)
                                            <option value="{{ $brandOption->id }}">{{ $brandOption->name }}</option>
                                        @endforeach
                                    </x-form.select>
                                    <x-form.error for="wizard_brand_id" />
                                </div>
                            @else
                                <div class="mt-3">
                                    <x-form.label for="new_brand_name" required>Brand name</x-form.label>
                                    <x-form.input id="new_brand_name" wire:model="new_brand_name"
                                        :error="$errors->has('new_brand_name')" />
                                    <x-form.error for="new_brand_name" />
                                </div>
                            @endif
                        @else
                            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                                A new client starts with a fresh brand.
                            </p>
                            <x-form.label for="new_brand_name" required>Brand name</x-form.label>
                            <x-form.input id="new_brand_name" wire:model="new_brand_name"
                                :error="$errors->has('new_brand_name')" />
                            <x-form.error for="new_brand_name" />
                        @endif
                    </fieldset>
                </div>
            @endif

            {{-- ============ 2: Campaign ============ --}}
            @if ($step === 2)
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Name the campaign</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    A campaign plans and measures work for one brand over a time period. Dates are optional.
                </p>

                <div class="mt-6 space-y-5">
                    <div>
                        <x-form.label for="campaign_name" required>Campaign name</x-form.label>
                        <x-form.input id="campaign_name" wire:model="campaign_name" :error="$errors->has('campaign_name')" />
                        <x-form.error for="campaign_name" />
                    </div>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <x-form.label for="campaign_start_at">Starts</x-form.label>
                            <x-form.input id="campaign_start_at" wire:model="campaign_start_at" type="datetime-local"
                                :error="$errors->has('campaign_start_at')" />
                            <x-form.error for="campaign_start_at" />
                        </div>
                        <div>
                            <x-form.label for="campaign_end_at">Ends</x-form.label>
                            <x-form.input id="campaign_end_at" wire:model="campaign_end_at" type="datetime-local"
                                :error="$errors->has('campaign_end_at')" />
                            <x-form.error for="campaign_end_at" />
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        The campaign starts as a draft — you can set spend and status once it’s created.
                    </p>
                </div>
            @endif

            {{-- ============ 3: Seeding run ============ --}}
            @if ($step === 3)
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Add a seeding run?</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    A seeding run sends product to creators under this campaign. This is optional — leave it off to add one later.
                </p>

                <label class="mt-6 flex items-start gap-3">
                    <input type="checkbox" wire:model.live="with_seeding"
                        class="mt-0.5 h-5 w-5 cursor-pointer rounded-md border-gray-300 text-brand-500 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900">
                    <span class="text-sm font-medium text-gray-800 dark:text-white/90">Yes, set up a seeding run now</span>
                </label>

                @if ($with_seeding)
                    <div class="mt-5 space-y-5 rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                        <div>
                            <x-form.label for="run_name" required>Run name</x-form.label>
                            <x-form.input id="run_name" wire:model="run_name" :error="$errors->has('run_name')" />
                            <x-form.error for="run_name" />
                        </div>
                        <div x-data="{ t: @js($run_type), map: @js($typeDescriptions) }">
                            <x-form.label for="run_type" required>Seeding type</x-form.label>
                            <x-form.select id="run_type" wire:model="run_type" x-on:change="t = $event.target.value"
                                :error="$errors->has('run_type')">
                                <option value="">Select a type…</option>
                                @foreach ($seedingTypes as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </x-form.select>
                            <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400" x-text="map[t] ?? ''"></p>
                            <x-form.error for="run_type" />
                        </div>
                        @if ($brand_mode === 'existing')
                            <div>
                                <x-form.label for="run_product_id">Primary product</x-form.label>
                                <x-form.select id="run_product_id" wire:model="run_product_id"
                                    :error="$errors->has('run_product_id')">
                                    <option value="">No product yet</option>
                                    @foreach ($products as $productOption)
                                        <option value="{{ $productOption->id }}">{{ $productOption->name }}</option>
                                    @endforeach
                                </x-form.select>
                                <x-form.error for="run_product_id" />
                            </div>
                        @else
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Add a product on the run’s page once the brand exists.
                            </p>
                        @endif
                    </div>
                @endif
            @endif

            {{-- ============ 4: Creators ============ --}}
            @if ($step === 4)
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Add creators</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Pick who takes part. Anyone on their no-go list for this brand, or marked ‘do not contact or book’, is flagged now and skipped on create.
                </p>

                <div class="mt-6 space-y-4">
                    <x-form.input type="search" wire:model.live.debounce.300ms="creator_search"
                        placeholder="Search by name or handle…" aria-label="Search creators" />

                    @if ($candidates->isEmpty())
                        <x-states.empty title="No creators match">Try another search, or add creators later on the campaign page.</x-states.empty>
                    @else
                        <div class="max-h-96 overflow-y-auto rounded-xl border border-gray-200 dark:border-gray-800">
                            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($candidates->take(50) as $creator)
                                    <li wire:key="wizard-creator-{{ $creator->id }}">
                                        <label class="flex items-start gap-3 px-3 py-2.5">
                                            <input type="checkbox" wire:model.live="selected_creator_ids" value="{{ $creator->id }}"
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
                                                @if (in_array((int) $creator->id, $blocklistedIds, true))
                                                    <span class="mt-0.5 block text-xs font-medium text-warning-500">Marked ‘do not contact or book’ — will be skipped.</span>
                                                @elseif (in_array((int) $creator->id, $restrictedIds, true))
                                                    <span class="mt-0.5 block text-xs font-medium text-warning-500">On their no-go list for {{ $currentBrandName }} — will be skipped.</span>
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
                                    <x-ui.button size="sm" wire:click="createCreator" wire:loading.attr="disabled"
                                        wire:target="createCreator">Add creator</x-ui.button>
                                    <x-ui.button size="sm" variant="outline" wire:click="$set('showNewCreatorForm', false)">Cancel</x-ui.button>
                                </div>
                            </div>
                        @else
                            @can('create', \App\Modules\CRM\Models\Creator::class)
                                <button type="button" wire:click="$set('showNewCreatorForm', true)"
                                    class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                    + New creator
                                </button>
                            @endcan
                        @endif
                    </div>

                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ count($selected_creator_ids) }} selected</p>
                </div>
            @endif

            {{-- ============ 5: Review ============ --}}
            @if ($step === 5)
                @php
                    $reviewClient = $client_mode === 'new'
                        ? $new_client_name
                        : ($clients->firstWhere('id', (int) $wizard_client_id)?->name ?? '—');
                    $reviewBrand = $brand_mode === 'new'
                        ? $new_brand_name
                        : ($brands->firstWhere('id', (int) $wizard_brand_id)?->name ?? '—');
                    $reviewProduct = $run_product_id !== ''
                        ? ($products->firstWhere('id', (int) $run_product_id)?->name ?? '—')
                        : null;
                @endphp
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Review &amp; create</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Check the details below. The campaign is created as a draft.
                </p>

                <dl class="mt-6 divide-y divide-gray-100 dark:divide-gray-800">
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">Client</dt>
                        <dd class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $reviewClient }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">Brand</dt>
                        <dd class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $reviewBrand }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">Campaign</dt>
                        <dd class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $campaign_name }}</dd>
                    </div>
                    @if ($with_seeding)
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-sm text-gray-500 dark:text-gray-400">Seeding run</dt>
                            <dd class="text-right text-sm font-medium text-gray-800 dark:text-white/90">
                                {{ $run_name }}
                                @if ($run_type !== '')
                                    <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ \App\Shared\Enums\SeedingType::from($run_type)->label() }}</span>
                                @endif
                                @if ($reviewProduct !== null)
                                    <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $reviewProduct }}</span>
                                @endif
                            </dd>
                        </div>
                    @endif
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">Creators</dt>
                        <dd class="text-sm font-medium text-gray-800 dark:text-white/90">{{ count($selected_creator_ids) }} selected</dd>
                    </div>
                </dl>

                <p class="mt-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-500 dark:border-gray-800 dark:bg-white/[0.02] dark:text-gray-400">
                    Anyone on their no-go list for this brand, or marked ‘do not contact or book’, is skipped, and the skipped names are reported on the next screen.
                </p>
            @endif

            {{-- Footer --}}
            <div class="mt-8 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-5 dark:border-gray-800">
                @if ($step > 1)
                    <x-ui.button variant="outline" wire:click="back">Back</x-ui.button>
                @endif

                <div class="grow"></div>

                @if ($step >= 2 && $step <= 4)
                    <button type="button" wire:click="createNow" wire:loading.attr="disabled" wire:target="createNow"
                        class="text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">
                        Skip the rest — create the campaign now
                    </button>
                @endif

                @if ($step < 5)
                    <x-ui.button wire:click="next" wire:loading.attr="disabled" wire:target="next">Continue</x-ui.button>
                @else
                    <x-ui.button wire:click="finish" wire:loading.attr="disabled" wire:target="finish">
                        <span wire:loading.remove wire:target="finish">Create campaign</span>
                        <span wire:loading wire:target="finish">Creating…</span>
                    </x-ui.button>
                @endif
            </div>
        </div>
    @endif
</div>
