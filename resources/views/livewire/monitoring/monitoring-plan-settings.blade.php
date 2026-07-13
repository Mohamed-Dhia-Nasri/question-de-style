<div class="mx-auto max-w-3xl">

    {{-- What this page is --}}
    <div class="rounded-2xl border border-blue-200 bg-blue-50 p-5 dark:border-blue-500/30 dark:bg-blue-500/10">
        <div class="flex gap-3">
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-600 dark:text-blue-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd"/></svg>
            <div class="text-sm leading-relaxed text-blue-800 dark:text-blue-200">
                <p class="font-semibold">These settings control your external data costs.</p>
                <p class="mt-1">
                    QDS collects posts, stories and follower numbers through <span class="font-medium">Apify</span>, an external
                    data service that charges per item collected. The more often we check, the fresher your dashboards —
                    and the higher the Apify bill. Every price on this page is <span class="font-medium">Apify's cost, not a QDS fee</span>,
                    estimated for your current roster.
                </p>
            </div>
        </div>
    </div>

    {{-- The settings, one per row, each with its own price --}}
    <div class="mt-5 divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white dark:divide-gray-800 dark:border-gray-800 dark:bg-white/[0.03]">

        <div class="grid gap-3 p-5 sm:grid-cols-[1fr_220px] sm:items-start md:p-6">
            <div>
                <div class="flex items-center gap-2">
                    <label for="plan-campaign" class="text-sm font-semibold text-gray-800 dark:text-white/90">
                        Creators in an active campaign
                    </label>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-theme-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                        ~${{ number_format($estimate['content_campaign'], 0) }}/mo
                    </span>
                </div>
                <p class="mt-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                    How often we check their new posts and refresh likes &amp; views — this is where campaign results
                    come from, so it deserves the fastest pace. Right now that's
                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ number_format($roster['campaign_ig'] + $roster['campaign_tt']) }} accounts</span>.
                </p>
            </div>
            <select id="plan-campaign" wire:model.live="campaign"
                class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                @foreach ($contentIntervals as $hours => $label)
                    <option value="{{ $hours }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="grid gap-3 p-5 sm:grid-cols-[1fr_220px] sm:items-start md:p-6">
            <div>
                <div class="flex items-center gap-2">
                    <label for="plan-baseline" class="text-sm font-semibold text-gray-800 dark:text-white/90">
                        All other creators
                    </label>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-theme-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                        ~${{ number_format($estimate['content_baseline'], 0) }}/mo
                    </span>
                </div>
                <p class="mt-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                    Everyday monitoring of the rest of the roster. New posts appear after the next check, so a slower
                    pace means later — but much cheaper. Creators who stopped posting are slowed down automatically.
                </p>
            </div>
            <select id="plan-baseline" wire:model.live="baseline"
                class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                @foreach ($contentIntervals as $hours => $label)
                    <option value="{{ $hours }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="grid gap-3 p-5 sm:grid-cols-[1fr_220px] sm:items-start md:p-6">
            <div>
                <div class="flex items-center gap-2">
                    <label for="plan-stories" class="text-sm font-semibold text-gray-800 dark:text-white/90">
                        Instagram stories
                    </label>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-theme-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                        ~${{ number_format($estimate['stories'], 0) }}/mo
                    </span>
                </div>
                <p class="mt-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                    Stories vanish after 24 hours — whatever we don't catch is gone for good.
                    Twice a day is the safe minimum if stories matter to your reporting.
                </p>
            </div>
            <select id="plan-stories" wire:model.live="stories"
                class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                @foreach ($storyOptions as $n => $label)
                    <option value="{{ $n }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="grid gap-3 p-5 sm:grid-cols-[1fr_220px] sm:items-start md:p-6">
            <div>
                <div class="flex items-center gap-2">
                    <label for="plan-profile" class="text-sm font-semibold text-gray-800 dark:text-white/90">
                        Follower counts &amp; bios
                    </label>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-theme-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                        ~${{ number_format($estimate['profiles'], 0) }}/mo
                    </span>
                </div>
                <p class="mt-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                    Sets how detailed the follower-growth charts are. Follower numbers move slowly —
                    weekly is enough for most agencies and costs almost nothing.
                </p>
            </div>
            <select id="plan-profile" wire:model.live="profile"
                class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                @foreach ($profileIntervals as $hours => $label)
                    <option value="{{ $hours }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="grid gap-3 p-5 sm:grid-cols-[1fr_220px] sm:items-start md:p-6">
            <div>
                <label for="plan-apify" class="text-sm font-semibold text-gray-800 dark:text-white/90">
                    Your Apify subscription
                </label>
                <p class="mt-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                    Bigger subscriptions get cheaper per-item prices. This choice only adjusts the estimate below —
                    the actual subscription is managed on apify.com.
                </p>
            </div>
            <select id="plan-apify" wire:model.live="apifyPlan"
                class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                @foreach ($apifyPlans as $plan)
                    <option value="{{ $plan }}">{{ ucfirst(strtolower($plan)) }} — ${{ $planFees[$plan] }}/month</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Warnings about the current choices --}}
    @if ($warnings !== [])
        <div class="mt-5 space-y-2">
            @foreach ($warnings as $warning)
                <div class="flex gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625l6.28-10.875zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                    <span>{{ $warning }}</span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Total + save --}}
    <div class="mt-5 rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/[0.03]" aria-live="polite">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Estimated monthly cost</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Your roster today: {{ number_format($roster['ig_accounts']) }} Instagram ·
                    {{ number_format($roster['tt_accounts']) }} TikTok accounts
                </p>
            </div>
            <p class="text-3xl font-bold text-gray-800 dark:text-white/90">
                ~${{ number_format($estimate['total'], 0) }}<span class="text-base font-medium text-gray-500 dark:text-gray-400"> / month</span>
            </p>
        </div>

        <dl class="mt-4 space-y-1.5 border-t border-gray-100 pt-4 text-sm dark:border-gray-800">
            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Creators in active campaigns</dt><dd class="font-medium text-gray-800 dark:text-white/90">${{ number_format($estimate['content_campaign'], 2) }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">All other creators</dt><dd class="font-medium text-gray-800 dark:text-white/90">${{ number_format($estimate['content_baseline'], 2) }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Instagram stories</dt><dd class="font-medium text-gray-800 dark:text-white/90">${{ number_format($estimate['stories'], 2) }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Follower counts &amp; bios</dt><dd class="font-medium text-gray-800 dark:text-white/90">${{ number_format($estimate['profiles'], 2) }}</dd></div>
            <div class="flex justify-between">
                <dt class="text-gray-500 dark:text-gray-400">
                    Keeping older campaign posts up to date
                    <span class="block text-theme-xs">Runs automatically once a day — not a setting.</span>
                </dt>
                <dd class="font-medium text-gray-800 dark:text-white/90">${{ number_format($estimate['campaign_refresh'], 2) }}</dd>
            </div>
            <div class="flex justify-between border-t border-gray-100 pt-2 dark:border-gray-800">
                <dt class="text-gray-500 dark:text-gray-400">Apify subscription fee</dt>
                <dd class="font-medium text-gray-800 dark:text-white/90">${{ number_format($estimate['plan_fee'], 2) }}</dd>
            </div>
        </dl>

        <p class="mt-3 text-theme-xs text-gray-500 dark:text-gray-400">
            This is an estimate based on typical posting activity — the real Apify bill follows what your creators
            actually post. Quiet months cost less{{ $estimate['approx'] ? '. Prices for this subscription tier are approximate' : '' }}.
        </p>

        <div class="mt-5 flex items-center gap-3 border-t border-gray-100 pt-5 dark:border-gray-800">
            <button type="button" wire:click="save" wire:loading.attr="disabled"
                class="rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:opacity-60">
                <span wire:loading.remove wire:target="save">Save these settings</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            @if ($saved)
                <span class="inline-flex items-center gap-1.5 text-sm font-medium text-green-600 dark:text-green-400">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none"><path d="M4 10.5l4 4 8-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Saved — takes effect within a few hours, no restart needed.
                </span>
            @else
                <span class="text-sm text-gray-500 dark:text-gray-400">Changes apply from the next monitoring run.</span>
            @endif
        </div>
    </div>

    {{-- Per-service price sheet --}}
    <div class="mt-5 rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">What each service costs</h3>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Every external data service the platform uses, what it charges per unit, and what that works out to
            per monitored creator per month with the settings above.
        </p>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-theme-xs uppercase tracking-wide text-gray-400 dark:border-gray-800 dark:text-gray-500">
                        <th scope="col" class="py-2 pr-4 font-medium">Service</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Unit price</th>
                        <th scope="col" class="py-2 pr-4 text-right font-medium">Per creator / mo</th>
                        <th scope="col" class="py-2 text-right font-medium">Whole roster / mo</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($services as $row)
                        <tr @class(['opacity-50' => ! $row['active']])>
                            <td class="py-3 pr-4">
                                <span class="font-medium text-gray-800 dark:text-white/90">{{ $row['service'] }}</span>
                                <span class="block text-theme-xs text-gray-500 dark:text-gray-400">{{ $row['detail'] }}</span>
                                @if ($row['note'] !== null)
                                    <span class="block text-theme-xs {{ $row['active'] ? 'text-gray-400 dark:text-gray-500' : 'text-amber-600 dark:text-amber-400' }}">{{ $row['note'] }}</span>
                                @endif
                            </td>
                            <td class="py-3 pr-4 align-top text-gray-600 dark:text-gray-300">{{ $row['unit'] }}</td>
                            <td class="py-3 pr-4 text-right align-top font-medium text-gray-800 dark:text-white/90">
                                @if ($row['per_creator'] === null)
                                    —
                                @elseif ($row['per_creator'] > 0 && $row['per_creator'] < 0.01)
                                    &lt;&nbsp;$0.01
                                @else
                                    ${{ number_format($row['per_creator'], 2) }}
                                @endif
                            </td>
                            <td class="py-3 text-right align-top font-medium text-gray-800 dark:text-white/90">
                                ${{ number_format($row['monthly'], 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-theme-xs text-gray-500 dark:text-gray-400">
            "Per creator" means per monitored account under the current settings and typical posting activity.
            Greyed-out services are switched off and bill nothing — their figures show what turning them on would
            cost. The Google AI rows are billed by Google, not Apify, and are not part of the total above.
        </p>
    </div>
</div>
