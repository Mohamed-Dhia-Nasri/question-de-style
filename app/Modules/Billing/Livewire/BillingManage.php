<?php

namespace App\Modules\Billing\Livewire;

use App\Models\Tenant;
use App\Modules\Billing\Exceptions\StripeApiException;
use App\Modules\Billing\Http\StripeClient;
use App\Modules\Billing\Models\SubscriptionPlan;
use App\Modules\Billing\Services\BillingManager;
use App\Modules\Billing\Services\SeatLimiter;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Billing page (ADR-0021) — OWNER-ONLY (the billing.manage gate: owner is
 * a tenant attribute, not a role). Subscribing goes to Stripe Checkout;
 * everything else (payment methods, invoices, plan changes, cancellation)
 * goes to the Stripe Billing Portal. State returns via verified webhooks —
 * this page never mutates subscription rows itself.
 */
class BillingManage extends Component
{
    public function mount(): void
    {
        $this->authorize('billing.manage');
    }

    public function subscribe(string $planCode, BillingManager $billing): void
    {
        // Re-authorize on EVERY action (the UsersIndex doctrine).
        $this->authorize('billing.manage');

        // A live subscription already exists: a plan CHANGE must go through
        // the Billing Portal, never a second subscription-mode Checkout.
        // Stripe Checkout would mint a NEW subscription without cancelling the
        // old one, double-billing the tenant (the local supersede only
        // rewrites our mirror row, it issues no Stripe cancel). Refuse
        // server-side — the blade also hides the button, but a crafted
        // Livewire call must not slip past. (ADR-0021 §plan changes.)
        if ($this->tenant()->currentSubscription() !== null) {
            $this->dispatch('notify', type: 'error', message: 'You already have a subscription — change your plan in the billing portal.');

            return;
        }

        /** @var SubscriptionPlan|null $plan */
        $plan = SubscriptionPlan::query()
            ->where('code', $planCode)
            ->where('is_active', true)
            ->first();

        if ($plan === null || ! $plan->isPurchasable()) {
            $this->dispatch('notify', type: 'error', message: 'This plan is not available for purchase.');

            return;
        }

        try {
            $url = $billing->checkoutUrl($this->tenant(), $plan);
        } catch (StripeApiException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        $this->redirect($url);
    }

    public function openPortal(BillingManager $billing): void
    {
        $this->authorize('billing.manage');

        try {
            $url = $billing->portalUrl($this->tenant());
        } catch (StripeApiException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return;
        }

        $this->redirect($url);
    }

    public function render(StripeClient $stripe, SeatLimiter $seats): View
    {
        $tenant = $this->tenant();
        $subscription = $tenant->currentSubscription();

        return view('livewire.billing.billing-manage', [
            'tenant' => $tenant,
            'subscription' => $subscription,
            'plans' => SubscriptionPlan::query()->where('is_active', true)->orderBy('max_seats')->get(),
            'seatsUsed' => $seats->activeSeats((int) $tenant->id),
            'stripeConfigured' => $stripe->isConfigured(),
        ]);
    }

    private function tenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = auth()->user()->tenant;

        return $tenant;
    }
}
