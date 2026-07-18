<div class="space-y-6">
    @if (! $stripeConfigured)
        <x-ui.alert variant="warning">
            Stripe is not configured on this environment (no STRIPE_SECRET) — checkout and the
            billing portal are unavailable.
        </x-ui.alert>
    @endif

    {{-- Current subscription --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 class="mb-4 text-base font-semibold text-gray-800 dark:text-white/90">Current subscription</h3>

        @if ($subscription !== null)
            <div class="flex flex-wrap items-center gap-4">
                <div>
                    <p class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $subscription->plan->name }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ $subscription->plan->billing_interval->label() }} ·
                        {{ $seatsUsed }} / {{ $subscription->seatLimit() }} seats used
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    @if ($subscription->allowsProductAccess())
                        <x-ui.badge color="success">{{ $subscription->status->label() }}</x-ui.badge>
                    @else
                        <x-ui.badge color="error">{{ $subscription->status->label() }}</x-ui.badge>
                    @endif
                    @if ($subscription->status === \App\Shared\Enums\SubscriptionStatus::PastDue)
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            Payment failed — update the payment method in the billing portal.
                        </span>
                    @endif
                    @if ($subscription->cancel_at_period_end && $subscription->current_period_ends_at !== null)
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            Cancels {{ $subscription->current_period_ends_at->toFormattedDateString() }}
                        </span>
                    @endif
                </div>
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No subscription yet — choose a plan below to get started.
            </p>
        @endif

        @if ($tenant->stripe_customer_id !== null)
            <div class="mt-4">
                <x-ui.button wire:click="openPortal" wire:loading.attr="disabled" :disabled="! $stripeConfigured">
                    Open billing portal
                </x-ui.button>
                <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                    Payment methods, invoices, plan changes and cancellation are managed on Stripe's
                    secure portal. Your data is never deleted when a subscription ends.
                </p>
            </div>
        @endif
    </div>

    {{-- Plan catalog --}}
    <div>
        <h3 class="mb-4 text-base font-semibold text-gray-800 dark:text-white/90">Plans</h3>
        <div class="grid gap-6 md:grid-cols-3">
            @foreach ($plans as $plan)
                <div wire:key="plan-{{ $plan->code }}"
                    class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $plan->name }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ $plan->max_seats }} seats · {{ $plan->billing_interval->label() }}
                    </p>
                    <div class="mt-4">
                        @if ($subscription !== null && $subscription->subscription_plan_id === $plan->id)
                            <x-ui.badge color="success">Current plan</x-ui.badge>
                        @elseif ($subscription !== null)
                            {{-- A live subscription already exists: plan changes go through
                                 the Stripe Billing Portal (prorated onto the SAME subscription),
                                 never a second Checkout that would double-bill. --}}
                            <x-ui.button wire:click="openPortal" wire:loading.attr="disabled"
                                :disabled="! $stripeConfigured">
                                Change plan in portal
                            </x-ui.button>
                        @else
                            <x-ui.button wire:click="subscribe('{{ $plan->code }}')" wire:loading.attr="disabled"
                                :disabled="! $stripeConfigured || ! $plan->isPurchasable()">
                                Subscribe
                            </x-ui.button>
                            @unless ($plan->isPurchasable())
                                <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">Not yet available.</p>
                            @endunless
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-theme-xs text-gray-500 dark:text-gray-400">
            Downgrading below the current team size never removes members — the team is marked
            over-limit and further team changes are blocked until active members fit the new limit.
        </p>
    </div>
</div>
