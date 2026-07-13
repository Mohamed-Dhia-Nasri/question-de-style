<div class="space-y-6">
    @if ($enforced && ($subscription === null || ! $subscription->allowsProductAccess()))
        <x-ui.alert variant="error">
            This workspace has no active subscription — the product areas are unavailable.
            @if ($isOwner)
                Visit <a href="{{ route('account.billing') }}" class="font-medium underline">Billing</a> to subscribe or fix the payment.
            @else
                Ask the workspace owner to restore the subscription.
            @endif
        </x-ui.alert>
    @endif

    @if ($overLimit)
        <x-ui.alert variant="warning">
            The team is over its seat limit ({{ $seatsUsed }} active members, {{ $seatLimit }} seats).
            Deactivate members or upgrade the plan before making further team changes.
        </x-ui.alert>
    @endif

    <div class="grid gap-6 md:grid-cols-3">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-theme-xs uppercase text-gray-400">Workspace</p>
            <p class="mt-2 text-lg font-semibold text-gray-800 dark:text-white/90">{{ $tenant->name }}</p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Owner: {{ $tenant->owner?->display_name ?? '—' }}
            </p>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-theme-xs uppercase text-gray-400">Plan</p>
            @if ($subscription !== null)
                <p class="mt-2 text-lg font-semibold text-gray-800 dark:text-white/90">
                    {{ $subscription->plan->name }}
                </p>
                <p class="mt-1 flex items-center gap-2 text-sm">
                    @if ($subscription->allowsProductAccess())
                        <x-ui.badge color="success">{{ $subscription->status->label() }}</x-ui.badge>
                    @else
                        <x-ui.badge color="error">{{ $subscription->status->label() }}</x-ui.badge>
                    @endif
                    @if ($subscription->cancel_at_period_end && $subscription->current_period_ends_at !== null)
                        <span class="text-gray-500 dark:text-gray-400">
                            ends {{ $subscription->current_period_ends_at->toFormattedDateString() }}
                        </span>
                    @endif
                </p>
            @else
                <p class="mt-2 text-lg font-semibold text-gray-800 dark:text-white/90">No subscription</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ $enforced ? 'A plan is required to use the product areas.' : 'Subscription enforcement is not enabled.' }}
                </p>
            @endif
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-theme-xs uppercase text-gray-400">Team seats</p>
            <p class="mt-2 text-lg font-semibold text-gray-800 dark:text-white/90">
                {{ $seatsUsed }} / {{ $seatLimit ?? '∞' }} used
            </p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Active members consume one seat each; pending invitations consume none.
            </p>
        </div>
    </div>

    @if ($isOwner)
        <div>
            <a href="{{ route('account.billing') }}">
                <x-ui.button variant="outline">Manage billing</x-ui.button>
            </a>
        </div>
    @endif
</div>
