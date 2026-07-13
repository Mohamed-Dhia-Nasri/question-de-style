<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Billing\Http\Middleware\EnsureTenantSubscribed;
use App\Modules\Billing\Models\TenantSubscription;
use App\Modules\CRM\Models\Brand;
use App\Platform\Export\Models\ExportJob;
use App\Shared\Enums\RoleName;
use App\Shared\Enums\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Server-side subscription enforcement (ADR-0021): the 'subscribed'
 * middleware (EnsureTenantSubscribed) gates every PRODUCT surface —
 * dashboard, reports, crm, monitoring, discovery, and the signed download
 * routes — on config('billing.enforced') plus a live access-allowing
 * TenantSubscription (SubscriptionStatus::allowsProductAccess: ACTIVE,
 * TRIALING, PAST_DUE pass; everything else blocks).
 *
 * Deliberately NOT gated: the account/billing recovery area, user admin,
 * and the auth flow — a lapsed tenant must always be able to reach billing
 * recovery, reduce seats, and log in/out. No business data is ever deleted
 * on lapse.
 */
class SubscriptionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /** Product surfaces behind the 'subscribed' middleware. */
    private const GATED_ROUTES = ['/dashboard', '/crm', '/monitoring', '/discovery'];

    /** An ADMIN in the default tenant, logged in. */
    private function actingAsAdmin(): User
    {
        $this->seedRoles();

        $admin = $this->makeUser(RoleName::Admin);

        $this->actingAs($admin);

        return $admin;
    }

    /** Make the given user the tenant owner (the billing.manage gate). */
    private function makeOwner(User $user): void
    {
        $this->defaultTenant->forceFill(['owner_user_id' => $user->id])->save();
    }

    public function test_enforcement_off_allows_product_access_without_any_subscription(): void
    {
        $this->actingAsAdmin();

        // Default posture (QDS_BILLING_ENFORCED=false): the founding tenant
        // predates billing, so the gate must be a no-op with zero rows.
        $this->assertFalse((bool) config('billing.enforced'));
        $this->assertDatabaseCount('tenant_subscriptions', 0);

        foreach (self::GATED_ROUTES as $uri) {
            $this->assertSame(
                200,
                $this->get($uri)->getStatusCode(),
                "{$uri} must stay open while billing.enforced is off",
            );
        }
    }

    public function test_enforcement_on_without_subscription_redirects_every_product_surface_to_account(): void
    {
        config(['billing.enforced' => true]);

        $this->actingAsAdmin();

        foreach ([...self::GATED_ROUTES, '/reports'] as $uri) {
            $response = $this->get($uri);

            $this->assertSame(302, $response->getStatusCode(), "{$uri} must redirect when blocked");
            $this->assertSame(
                route('account.index'),
                $response->headers->get('Location'),
                "{$uri} must land an unsubscribed tenant on the account page",
            );
        }
    }

    public function test_subscription_state_matrix_controls_product_access(): void
    {
        config(['billing.enforced' => true]);

        $this->actingAsAdmin();

        // SubscriptionStatus::allowsProductAccess per state (ADR-0021).
        $matrix = [
            [SubscriptionStatus::Active, true],
            [SubscriptionStatus::Trialing, true],
            [SubscriptionStatus::PastDue, true], // dunning grace window
            [SubscriptionStatus::Incomplete, false],
            [SubscriptionStatus::Unpaid, false],
            [SubscriptionStatus::Paused, false],
            [SubscriptionStatus::Canceled, false],
        ];

        foreach ($matrix as [$status, $allowsAccess]) {
            $subscription = TenantSubscription::factory()->status($status)->create();

            if ($status === SubscriptionStatus::Canceled) {
                // CANCELED is terminal: liveFor() excludes it entirely, so
                // the block below is the "no live row" path — the same
                // outcome a tenant with only billing history gets.
                $this->assertNull(
                    TenantSubscription::liveFor((int) $this->defaultTenant->id),
                    'A canceled subscription must not count as live',
                );
            }

            $response = $this->get('/crm');

            if ($allowsAccess) {
                $this->assertSame(
                    200,
                    $response->getStatusCode(),
                    "status {$status->value} must allow product access",
                );
            } else {
                $this->assertSame(
                    302,
                    $response->getStatusCode(),
                    "status {$status->value} must block product access",
                );
                $this->assertSame(
                    route('account.index'),
                    $response->headers->get('Location'),
                    "status {$status->value} must redirect to the account page",
                );
            }

            // One live row per tenant (partial unique index) — clear the
            // row so the next matrix iteration starts from a clean slate.
            $subscription->delete();
        }
    }

    public function test_blocked_tenant_keeps_account_admin_and_auth_surfaces(): void
    {
        config(['billing.enforced' => true]);

        $admin = $this->actingAsAdmin();
        $this->makeOwner($admin);

        // Recovery surfaces are deliberately outside the gate: account
        // state, billing (owner-only gate billing.manage), and user admin
        // (so seats can still be reduced).
        $this->get('/account')->assertOk();
        $this->get('/account/billing')->assertOk();
        $this->get('/admin/users')->assertOk();

        // The auth flow itself is never gated: a blocked user can log out…
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();

        // …and log back in (factory password is 'password'); the login POST
        // succeeds — only the subsequent product pages redirect.
        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_json_request_to_gated_route_returns_402_when_blocked(): void
    {
        config(['billing.enforced' => true]);

        $this->actingAsAdmin();

        // API-shaped clients get the payment-required status, not a
        // redirect they cannot follow.
        $this->getJson('/crm')->assertStatus(402);
    }

    public function test_incomplete_subscription_recovers_access_once_webhook_flips_it_active(): void
    {
        config(['billing.enforced' => true]);

        $this->actingAsAdmin();

        $subscription = TenantSubscription::factory()
            ->status(SubscriptionStatus::Incomplete)
            ->create();

        $this->get('/crm')->assertRedirect(route('account.index'));

        // The webhook synchronizer is the only writer of this state —
        // simulate its invoice.paid outcome by flipping the SAME row.
        $subscription->update(['status' => SubscriptionStatus::Active]);

        $this->get('/crm')->assertOk();
        $this->assertSame(
            SubscriptionStatus::Active,
            $subscription->fresh()->status,
            'Recovery must reuse the existing subscription row, not create a new one',
        );
    }

    public function test_expiration_blocks_product_but_preserves_data_and_account_access(): void
    {
        config(['billing.enforced' => true]);

        $this->actingAsAdmin();

        $subscription = TenantSubscription::factory()->create(); // ACTIVE
        $brand = Brand::factory()->create();

        $this->get('/crm')->assertOk();

        // Subscription ends (customer.subscription.deleted): the mirror row
        // becomes terminal billing history, never a delete.
        $subscription->update([
            'status' => SubscriptionStatus::Canceled,
            'ended_at' => now(),
        ]);

        $this->get('/crm')->assertRedirect(route('account.index'));
        $this->get('/account')->assertOk();

        // Lapse never touches business data (ADR-0021): the tenant's rows
        // survive intact for when the subscription is restored.
        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $subscription->id,
            'status' => SubscriptionStatus::Canceled->value,
        ]);
    }

    public function test_signed_download_routes_are_subscription_gated(): void
    {
        config(['billing.enforced' => true]);

        $admin = $this->actingAsAdmin();

        $job = ExportJob::factory()->completed()->create(['user_id' => $admin->id]);

        // A perfectly VALID signature must not bypass the gate: 'subscribed'
        // sits after 'signed' in the route middleware, so the artifact is
        // never streamed to a lapsed tenant.
        $url = URL::signedRoute('exports.download', ['exportJob' => $job->id]);

        $this->get($url)->assertRedirect(route('account.index'));
    }

    public function test_client_viewer_on_reports_is_redirected_to_account_when_blocked(): void
    {
        config(['billing.enforced' => true]);

        $this->seedRoles();
        $viewer = $this->makeUser(RoleName::ClientViewer);
        $this->actingAs($viewer);

        // The /reports request itself must redirect like every product
        // surface. The landing page (/account) then 403s for CLIENT_VIEWER
        // because it lacks internal.access — an accepted v1 dead-end:
        // ADR-0016 dropped client accounts, so no client-facing billing
        // surface exists. We assert only the redirect here.
        $this->get('/reports')->assertRedirect(route('account.index'));
    }

    public function test_the_subscription_gate_is_a_livewire_persistent_middleware(): void
    {
        // ADR-0021 §5 adversarial finding: the `subscribed` gate runs on the
        // full-page GET but not on Livewire's global /livewire/update
        // endpoint, so a lapsed tenant with a page still open could keep
        // firing mutating component actions. The fix registers the gate as
        // PERSISTENT middleware — Livewire then re-applies it on every update
        // for components whose ORIGINAL route carried `subscribed`. Livewire
        // resolves this list at the real HTTP layer (Livewire::test bypasses
        // HTTP middleware), so we assert the registration; the gate's actual
        // blocking behavior is proven by the full-page redirect tests above.
        $this->assertContains(
            EnsureTenantSubscribed::class,
            Livewire::getPersistentMiddleware(),
            'EnsureTenantSubscribed must be a Livewire persistent middleware, or lapsed tenants could mutate data via /livewire/update.',
        );
    }
}
