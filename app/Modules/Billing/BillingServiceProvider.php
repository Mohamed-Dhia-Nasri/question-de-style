<?php

namespace App\Modules\Billing;

use App\Models\User;
use App\Modules\Billing\Console\SyncBillingPlansCommand;
use App\Modules\Billing\Http\Middleware\EnsureTenantSubscribed;
use App\Modules\Billing\Livewire\AccountOverview;
use App\Modules\Billing\Livewire\BillingManage;
use App\Modules\Billing\Livewire\TeamInvitationsPanel;
use App\Modules\Billing\Models\TeamInvitation;
use App\Modules\Billing\Policies\TeamInvitationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * SaaS billing & team management (ADR-0021, SaaS pivot Prompt 3).
 *
 * Write-owns SubscriptionPlan (config sync), TenantSubscription (webhook
 * synchronizer), TeamInvitation, and the tenants.stripe_customer_id
 * column. Stripe is a payment processor outside the frozen SRC-* data-
 * provider set; card data never touches QDS (Checkout + Billing Portal
 * are Stripe-hosted).
 */
class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        // Owner-only billing gate. Deliberately a DEFINED gate, not a
        // PermissionsCatalog permission: ENUM-RoleName is a closed set and
        // "owner" is a tenant attribute (tenants.owner_user_id, ADR-0019)
        // — billing authority follows the attribute, not a role. Spatie's
        // permission hook returns null for names it does not know, so this
        // definition is reached normally.
        Gate::define('billing.manage', function (User $user): bool {
            $tenant = $user->tenant;

            return $tenant !== null && (int) $tenant->owner_user_id === (int) $user->id;
        });

        // Invitations are user administration: same ADMIN-only permission
        // as ENT-User writes; tenancy is backstopped by TenantIsolationGate.
        Gate::policy(TeamInvitation::class, TeamInvitationPolicy::class);

        // ADR-0021 §5: the `subscribed` product gate runs on the full-page
        // GET but NOT on Livewire's global /livewire/update endpoint, so a
        // lapsed tenant with a page still open could otherwise keep firing
        // mutating component actions. Registering the gate as PERSISTENT
        // middleware makes Livewire re-apply it on every update, but only
        // for components whose ORIGINAL route carried `subscribed` — the
        // account/billing/team components (rendered on ungated routes) stay
        // reachable for billing recovery.
        Livewire::addPersistentMiddleware(EnsureTenantSubscribed::class);

        Livewire::component('billing.account-overview', AccountOverview::class);
        Livewire::component('billing.billing-manage', BillingManage::class);
        Livewire::component('billing.team-invitations', TeamInvitationsPanel::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncBillingPlansCommand::class]);
        }
    }
}
