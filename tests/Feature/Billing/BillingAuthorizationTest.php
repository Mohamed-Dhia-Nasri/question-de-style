<?php

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Billing\Livewire\BillingManage;
use App\Modules\Billing\Livewire\TeamInvitationsPanel;
use App\Modules\Billing\Models\SubscriptionPlan;
use App\Modules\Billing\Models\TeamInvitation;
use App\Modules\Billing\Models\TenantSubscription;
use App\Shared\Enums\RoleName;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\InteractsWithStripe;
use Tests\TestCase;

/**
 * Billing & team authorization and cross-tenant isolation (ADR-0021).
 *
 * The billing surface is OWNER-ONLY via the billing.manage gate — owner is
 * a tenant ATTRIBUTE (tenants.owner_user_id, ADR-0019), not a role, so even
 * a fully-privileged ADMIN of the same tenant is denied. Cross-tenant
 * reach is impossible by construction: every billing/team action operates
 * on the AUTHENTICATED USER'S OWN tenant, Stripe customer ids come only
 * from the tenants row (never client input), and webhook tenant resolution
 * trusts only the stripe_customer_id mapping — never payload metadata.
 */
class BillingAuthorizationTest extends TestCase
{
    use InteractsWithStripe;
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $ownerA;

    private User $ownerB;

    protected function setUp(): void
    {
        parent::setUp();

        // Two fully provisioned tenants (seeds roles itself); each owner is
        // an ADMIN of their own tenant and recorded in tenants.owner_user_id.
        [$this->tenantA, $this->tenantB] = $this->makeTenantPair();
        $this->ownerA = $this->tenantA->owner;
        $this->ownerB = $this->tenantB->owner;
    }

    /** A non-owner user provisioned inside the given tenant. */
    private function makeUserIn(Tenant $tenant, RoleName $role): User
    {
        return $this->withTenant($tenant, fn (): User => $this->makeUser($role));
    }

    // ------------------------------------------------------------------
    // 1. The billing.manage gate
    // ------------------------------------------------------------------

    public function test_billing_manage_gate_allows_only_the_tenants_own_owner(): void
    {
        $adminA = $this->makeUserIn($this->tenantA, RoleName::Admin);
        $analystA = $this->makeUserIn($this->tenantA, RoleName::Analyst);

        $this->assertTrue(
            Gate::forUser($this->ownerA)->allows('billing.manage'),
            'The tenant owner must pass the billing.manage gate',
        );

        // Owner is an attribute, not a role: a same-tenant ADMIN with every
        // permission is still denied, and so is any lesser role.
        $this->assertTrue(
            Gate::forUser($adminA)->denies('billing.manage'),
            'A non-owner ADMIN of the same tenant must be denied',
        );
        $this->assertTrue(
            Gate::forUser($analystA)->denies('billing.manage'),
            'An Analyst must be denied',
        );

        // The gate is strictly SELF-relative: it grants tenant B's owner
        // authority over tenant B's billing surface only. Every consumer
        // (route middleware, BillingManage) binds to auth()->user()->tenant,
        // so this grant can never be applied to tenant A — see the page
        // isolation tests below, and the schema test that a foreign user
        // can never even be RECORDED as owner.
        $this->assertTrue(
            Gate::forUser($this->ownerB)->allows('billing.manage'),
            "The other tenant's owner passes the gate for THEIR OWN tenant only",
        );
    }

    public function test_a_foreign_tenant_user_can_never_be_recorded_as_owner(): void
    {
        // The composite FK tenants(owner_user_id, id) → users(id, tenant_id)
        // makes "tenant B owned by a tenant-A user" unrepresentable — the
        // cross-tenant billing.manage grant cannot exist even as forged data.
        $adminA = $this->makeUserIn($this->tenantA, RoleName::Admin);

        $this->expectException(QueryException::class);

        $this->tenantB->forceFill(['owner_user_id' => $adminA->id])->save();
    }

    // ------------------------------------------------------------------
    // 2-3. Route-level authorization
    // ------------------------------------------------------------------

    public function test_account_page_is_reachable_by_every_staff_role(): void
    {
        foreach (RoleName::staff() as $role) {
            $user = $this->makeUserIn($this->tenantA, $role);

            $this->actingAs($user)
                ->get('/account')
                ->assertOk("staff role {$role->value} should reach the account page");
        }
    }

    public function test_account_page_is_denied_to_guests_and_client_viewers(): void
    {
        // Guest first — actingAs() below would stick for the whole test.
        $this->get('/account')->assertRedirect('/login');

        // CLIENT_VIEWER lacks internal.access — the account area is an
        // internal surface (ADR-0016 keeps external viewers out).
        $viewer = $this->makeUserIn($this->tenantA, RoleName::ClientViewer);

        $this->actingAs($viewer)->get('/account')->assertForbidden();
    }

    public function test_billing_page_is_owner_only(): void
    {
        $adminA = $this->makeUserIn($this->tenantA, RoleName::Admin);
        $analystA = $this->makeUserIn($this->tenantA, RoleName::Analyst);

        $this->actingAs($this->ownerA)->get('/account/billing')->assertOk();

        // AuthenticateSession pins the session to the first actor's
        // password hash — start a fresh session before switching users or
        // the middleware logs the request out (302) before the gate runs.
        $this->flushSession();

        // internal.access passes for both, so these 403s prove the
        // can:billing.manage middleware specifically.
        $this->actingAs($adminA)->get('/account/billing')->assertForbidden();

        $this->flushSession();
        $this->actingAs($analystA)->get('/account/billing')->assertForbidden();
    }

    // ------------------------------------------------------------------
    // 4-6. BillingManage Livewire component
    // ------------------------------------------------------------------

    public function test_billing_manage_mount_is_forbidden_for_non_owners(): void
    {
        $adminA = $this->makeUserIn($this->tenantA, RoleName::Admin);

        $this->actingAsTenant($this->tenantA);
        $this->actingAs($adminA);

        Livewire::test(BillingManage::class)->assertForbidden();
    }

    public function test_subscribe_creates_checkout_against_the_own_tenants_customer(): void
    {
        $this->fakeStripeCredentials();
        Http::fake([
            'api.stripe.com/v1/customers*' => Http::response(['id' => 'cus_a']),
            'api.stripe.com/v1/checkout/sessions*' => Http::response([
                'id' => 'cs_1',
                'url' => 'https://checkout.stripe.com/test',
            ]),
        ]);

        $plan = SubscriptionPlan::factory()->create(['code' => 'STUDIO']);

        $this->actingAsTenant($this->tenantA);
        $this->actingAs($this->ownerA);

        // The tenant has no Stripe customer yet: subscribing must first mint
        // one for THIS tenant, then hand exactly that id to Checkout.
        $this->assertNull($this->tenantA->stripe_customer_id);

        Livewire::test(BillingManage::class)
            ->call('subscribe', $plan->code)
            ->assertRedirect('https://checkout.stripe.com/test');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v1/checkout/sessions')
            && $request['customer'] === 'cus_a');

        // The minted customer id is persisted on the tenants row — the only
        // place billing actions ever read it from.
        $this->assertSame('cus_a', $this->tenantA->fresh()->stripe_customer_id);
    }

    public function test_open_portal_uses_only_the_tenants_own_stored_customer_id(): void
    {
        $this->fakeStripeCredentials();
        Http::fake([
            'api.stripe.com/v1/billing_portal/sessions*' => Http::response([
                'url' => 'https://billing.stripe.com/p/qds',
            ]),
        ]);

        // Both tenants have Stripe customers — the portal session must be
        // minted for the acting owner's own tenant, never the other one.
        $this->tenantA->forceFill(['stripe_customer_id' => 'cus_a'])->save();
        $this->tenantB->forceFill(['stripe_customer_id' => 'cus_b'])->save();

        $this->actingAsTenant($this->tenantA);
        $this->actingAs($this->ownerA);

        Livewire::test(BillingManage::class)
            ->call('openPortal')
            ->assertRedirect('https://billing.stripe.com/p/qds');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v1/billing_portal/sessions')
            && $request['customer'] === 'cus_a');
        Http::assertNotSent(fn (Request $request): bool => $request['customer'] === 'cus_b');
    }

    public function test_open_portal_without_a_customer_id_errors_and_sends_nothing(): void
    {
        // Credentials ARE configured — the failure below must be the missing
        // customer id, not a missing secret.
        $this->fakeStripeCredentials();
        Http::fake();

        $this->actingAsTenant($this->tenantA);
        $this->actingAs($this->ownerA);

        $this->assertNull($this->tenantA->stripe_customer_id);

        Livewire::test(BillingManage::class)
            ->call('openPortal')
            ->assertDispatched('notify', type: 'error')
            ->assertNoRedirect();

        Http::assertNothingSent();
    }

    public function test_billing_actions_reauthorize_on_every_call(): void
    {
        $adminA = $this->makeUserIn($this->tenantA, RoleName::Admin);
        $plan = SubscriptionPlan::factory()->create(['code' => 'STUDIO']);

        $this->fakeStripeCredentials();
        Http::fake();

        $this->actingAsTenant($this->tenantA);
        $this->actingAs($this->ownerA);

        // Mount succeeds while ownerA still owns the tenant. Two separate
        // pre-mounted instances: the Livewire test harness cannot reuse a
        // component snapshot after a forbidden call, so each stale
        // component gets exactly one action.
        $componentA = Livewire::test(BillingManage::class);
        $componentB = Livewire::test(BillingManage::class);

        // ...then ownership is transferred mid-session. Drop the cached
        // tenant relation so the gate re-reads the row, as a fresh request
        // would.
        $this->tenantA->forceFill(['owner_user_id' => $adminA->id])->save();
        $this->ownerA->unsetRelation('tenant');

        // Every action re-authorizes (the UsersIndex doctrine): a stale
        // mounted component grants nothing.
        $componentA->call('subscribe', $plan->code)->assertForbidden();
        $componentB->call('openPortal')->assertForbidden();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // 7. Team invitations panel authorization
    // ------------------------------------------------------------------

    public function test_team_panel_requires_users_manage(): void
    {
        $adminA = $this->makeUserIn($this->tenantA, RoleName::Admin);
        $analystA = $this->makeUserIn($this->tenantA, RoleName::Analyst);

        $this->actingAsTenant($this->tenantA);

        $this->actingAs($adminA);
        Livewire::test(TeamInvitationsPanel::class)->assertOk();

        // Analyst lacks users.manage — invitations are user administration.
        $this->actingAs($analystA);
        Livewire::test(TeamInvitationsPanel::class)->assertForbidden();
    }

    public function test_invite_is_forbidden_for_an_analyst_at_call_time(): void
    {
        $adminA = $this->makeUserIn($this->tenantA, RoleName::Admin);

        $this->actingAsTenant($this->tenantA);
        $this->actingAs($adminA);

        // Mount as ADMIN, then downgrade to Analyst before calling invite:
        // the per-action authorize must catch the demoted user.
        $component = Livewire::test(TeamInvitationsPanel::class);

        $adminA->syncRoles([RoleName::Analyst->value]);
        $adminA->unsetRelation('roles')->unsetRelation('permissions');

        $component
            ->set('email', 'newcomer@tenant-a.test')
            ->set('role', RoleName::Analyst->value)
            ->call('invite')
            ->assertForbidden();

        $this->assertDatabaseMissing('team_invitations', ['email' => 'newcomer@tenant-a.test']);
    }

    // ------------------------------------------------------------------
    // 8-9. Cross-tenant isolation of team & billing data
    // ------------------------------------------------------------------

    public function test_team_panel_lists_only_own_tenant_invitations(): void
    {
        $this->withTenant($this->tenantA, fn (): TeamInvitation => TeamInvitation::factory()->create([
            'email' => 'only-a@invites.test',
            'invited_by_user_id' => $this->ownerA->id,
        ]));
        $this->withTenant($this->tenantB, fn (): TeamInvitation => TeamInvitation::factory()->create([
            'email' => 'only-b@invites.test',
            'invited_by_user_id' => $this->ownerB->id,
        ]));

        $this->actingAsTenant($this->tenantA);
        $this->actingAs($this->ownerA);

        // The TenantScope on TeamInvitation confines the panel to tenant A.
        Livewire::test(TeamInvitationsPanel::class)
            ->assertSee('only-a@invites.test')
            ->assertDontSee('only-b@invites.test');
    }

    public function test_account_page_shows_only_the_own_tenants_subscription(): void
    {
        $planA = SubscriptionPlan::factory()->create(['name' => 'Atelier Alpha']);
        $planB = SubscriptionPlan::factory()->create(['name' => 'Boutique Beta']);

        $this->withTenant($this->tenantA, fn (): TenantSubscription => TenantSubscription::factory()
            ->create(['subscription_plan_id' => $planA->id]));
        $this->withTenant($this->tenantB, fn (): TenantSubscription => TenantSubscription::factory()
            ->create(['subscription_plan_id' => $planB->id]));

        $this->actingAs($this->ownerA)
            ->get('/account')
            ->assertOk()
            ->assertSee('Atelier Alpha')
            ->assertDontSee('Boutique Beta');
    }

    public function test_a_foreign_tenant_invitation_cannot_be_revoked(): void
    {
        $adminA = $this->makeUserIn($this->tenantA, RoleName::Admin);

        $invitationA = $this->withTenant($this->tenantA, fn (): TeamInvitation => TeamInvitation::factory()
            ->create(['invited_by_user_id' => $this->ownerA->id]));
        $invitationB = $this->withTenant($this->tenantB, fn (): TeamInvitation => TeamInvitation::factory()
            ->create(['invited_by_user_id' => $this->ownerB->id]));

        // Control: the same admin CAN delete a same-tenant invitation, so
        // the denial below is tenant isolation, not a missing permission.
        $this->assertTrue(Gate::forUser($adminA)->allows('delete', $invitationA));

        // The Gate::before TenantIsolationGate denies any ability against a
        // foreign-tenant model regardless of role (ADR-0020).
        $this->assertTrue(Gate::forUser($adminA)->denies('delete', $invitationB));
    }

    // ------------------------------------------------------------------
    // 10. Webhook tenant resolution
    // ------------------------------------------------------------------

    public function test_webhook_tenant_resolution_ignores_forged_metadata(): void
    {
        $this->fakeStripeCredentials();
        // No outbound Stripe call is needed for a subscription.created sync
        // — assert that stays true.
        Http::fake();

        $plan = SubscriptionPlan::factory()->create();

        $this->tenantA->forceFill(['stripe_customer_id' => 'cus_a'])->save();
        $this->tenantB->forceFill(['stripe_customer_id' => 'cus_b'])->save();

        // The object belongs to tenant B's customer but carries forged
        // metadata claiming tenant A: the trusted stripe_customer_id
        // mapping must win and the metadata must be ignored (ADR-0021).
        $event = $this->stripeEvent('customer.subscription.created', $this->stripeSubscriptionObject(
            'sub_forged',
            'cus_b',
            (string) $plan->stripe_price_id,
            'active',
            ['metadata' => ['qds_tenant_id' => (string) $this->tenantA->id]],
        ));

        $this->postStripeWebhook($event)->assertOk();

        $row = TenantSubscription::query()
            ->withoutGlobalScopes()
            ->where('stripe_subscription_id', 'sub_forged')
            ->sole();

        $this->assertSame((int) $this->tenantB->id, (int) $row->tenant_id);
        $this->assertDatabaseMissing('tenant_subscriptions', ['tenant_id' => $this->tenantA->id]);

        Http::assertNothingSent();
    }
}
