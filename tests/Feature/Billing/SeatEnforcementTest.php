<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Billing\Livewire\TeamInvitationsPanel;
use App\Modules\Billing\Models\SubscriptionPlan;
use App\Modules\Billing\Models\TeamInvitation;
use App\Modules\Billing\Models\TenantSubscription;
use App\Modules\Billing\Notifications\TeamInvitationNotification;
use App\Modules\Billing\Services\SeatLimiter;
use App\Modules\CRM\Livewire\Users\UsersIndex;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Seat accounting + every seat enforcement point (ADR-0021).
 *
 * Seat model under test: every ACTIVE user of the tenant consumes exactly
 * one seat — including the owner and the acting admin. Inactive users and
 * pending invitations consume nothing. Effective limit = seats_override ??
 * plan.max_seats; NULL (enforcement off) = unlimited; enforced with no
 * live access-allowing subscription = ZERO. The invariant is
 * active_members <= limit (at-limit is legal), and a downgrade below
 * current usage freezes every seat-consuming change — nothing is ever
 * auto-deleted — until active members fit again.
 *
 * Enforcement points covered: UsersIndex::save() (create active /
 * reactivate), UsersIndex::bulkSetActive(true) (all-or-nothing),
 * TeamInvitationsPanel::invite() (downgrade freeze), and guest invitation
 * acceptance (POST /invitations/{token}). TRUE concurrency (two writers on
 * the tenant seat lock) lives in its own test file.
 *
 * NOTE on arithmetic: TestCase::setUp() creates the default tenant with no
 * users; actingAsAdmin() adds ONE active member, so the actor itself
 * occupies a seat in every Livewire scenario. SeatLimiter::activeSeats()
 * is treated as the source of truth throughout.
 */
class SeatEnforcementTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ helpers

    private function actingAsAdmin(): User
    {
        $this->seedRoles();

        $admin = $this->makeUser(RoleName::Admin, ['display_name' => 'Root Admin']);
        $this->actingAs($admin);

        return $admin;
    }

    private function seats(): SeatLimiter
    {
        return app(SeatLimiter::class);
    }

    private function defaultTenantSeats(): int
    {
        return $this->seats()->activeSeats((int) $this->defaultTenant->id);
    }

    /** A live ACTIVE subscription for the default tenant on a plan of $maxSeats. */
    private function activeSubscription(int $maxSeats): TenantSubscription
    {
        return TenantSubscription::factory()->create([
            'subscription_plan_id' => SubscriptionPlan::factory()->seats($maxSeats)->create()->id,
        ]);
    }

    /** Turn enforcement on AND give the default tenant a live $maxSeats plan. */
    private function enforceSeatLimit(int $maxSeats): TenantSubscription
    {
        config(['billing.enforced' => true]);

        return $this->activeSubscription($maxSeats);
    }

    /** Drive the UsersIndex modal create form end-to-end for a new user. */
    private function submitNewUser(string $email, bool $active = true): Testable
    {
        return Livewire::test(UsersIndex::class)
            ->call('create')
            ->set('display_name', 'New Member')
            ->set('email', $email)
            ->set('role', RoleName::Analyst->value)
            ->set('active', $active)
            ->set('password', 'a-long-secure-password') // staff policy: min 12
            ->call('save');
    }

    /**
     * A pending invitation whose plaintext token we keep. The inviter is
     * passed explicitly because the factory default (User::factory()) would
     * create ANOTHER active user and silently consume a seat, wrecking the
     * arithmetic under test.
     *
     * @return array{0: TeamInvitation, 1: string}
     */
    private function mintInvitation(string $email, User $inviter): array
    {
        $token = Str::random(64);

        $invitation = TeamInvitation::factory()->create([
            'email' => $email,
            'token_hash' => TeamInvitation::hashToken($token),
            'invited_by_user_id' => $inviter->id,
        ]);

        return [$invitation, $token];
    }

    private function postAcceptance(string $token): TestResponse
    {
        return $this->post('/invitations/'.$token, [
            'display_name' => 'Invited Member',
            'password' => 'a-long-secure-password',
            'password_confirmation' => 'a-long-secure-password',
        ]);
    }

    // ------------------------------------------------------- seat accounting

    public function test_active_seats_counts_only_active_members_of_the_tenant(): void
    {
        $memberA = User::factory()->create();
        User::factory()->create();

        // None of these may count: suspended member, another tenant's
        // member, and an unanswered invitation (ADR-0021: pending
        // invitations reserve nothing — availability is re-checked at
        // acceptance instead).
        User::factory()->inactive()->create();

        $foreign = $this->makeTenant('Rival Agency');
        $this->withTenant($foreign, fn (): User => User::factory()->create());

        TeamInvitation::factory()->create(['invited_by_user_id' => $memberA->id]);

        $this->assertSame(2, $this->defaultTenantSeats(), 'Only active default-tenant users occupy seats.');
        $this->assertSame(1, $this->seats()->activeSeats((int) $foreign->id), 'Foreign-tenant members count against THEIR tenant only.');

        // Ownership is an attribute, not a seat class: promoting a counted
        // member to owner must neither add nor exempt a seat.
        $this->defaultTenant->forceFill(['owner_user_id' => $memberA->id])->save();

        $this->assertSame(2, $this->defaultTenantSeats(), 'The owner occupies one ordinary seat like anyone else.');
    }

    public function test_seat_allowance_is_unlimited_when_enforcement_is_off(): void
    {
        config(['billing.enforced' => false]);

        // Even a live 1-seat plan with the team already past it: OFF means
        // no allowance and no over-limit state at all.
        $this->activeSubscription(1);
        User::factory()->count(2)->create();

        $this->assertNull($this->seats()->limitFor($this->defaultTenant), 'Enforcement off: limitFor() is null (unlimited).');
        $this->assertFalse($this->seats()->overLimit($this->defaultTenant), 'Without a limit there is no over-limit state.');
    }

    public function test_seat_allowance_tracks_the_live_subscription_when_enforced(): void
    {
        config(['billing.enforced' => true]);

        $this->assertSame(0, $this->seats()->limitFor($this->defaultTenant), 'Enforced with no subscription at all: zero seats.');

        // A terminal subscription is billing history, not an allowance.
        TenantSubscription::factory()->canceled()->create([
            'subscription_plan_id' => SubscriptionPlan::factory()->seats(9)->create()->id,
        ]);

        $this->assertSame(0, $this->seats()->limitFor($this->defaultTenant), 'A canceled subscription grants no seats.');

        $live = $this->activeSubscription(5);

        $this->assertSame(5, $this->seats()->limitFor($this->defaultTenant), 'A live ACTIVE subscription grants plan.max_seats.');

        // Bespoke allowance beats the plan default.
        $live->update(['seats_override' => 2]);

        $this->assertSame(2, $this->seats()->limitFor($this->defaultTenant), 'seats_override wins over plan.max_seats.');
    }

    // --------------------------------------------- UsersIndex create / edit

    public function test_creating_an_active_user_below_the_limit_succeeds(): void
    {
        $this->actingAsAdmin();
        $this->enforceSeatLimit(3); // admin uses 1 of 3

        $this->submitNewUser('second@qds.test')
            ->assertHasNoErrors()
            ->assertSet('showForm', false)
            ->assertDispatched('notify', type: 'success');

        $created = User::query()->where('email', 'second@qds.test')->sole();
        $this->assertTrue($created->active);
        $this->assertSame(2, $this->defaultTenantSeats());
    }

    public function test_creating_the_user_that_exactly_fills_the_last_seat_succeeds(): void
    {
        $this->actingAsAdmin();
        $this->enforceSeatLimit(2); // admin uses 1 of 2 — exactly one seat free

        $this->submitNewUser('last-seat@qds.test')
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'success');

        $this->assertTrue(User::query()->where('email', 'last-seat@qds.test')->sole()->active);
        $this->assertSame(2, $this->defaultTenantSeats());
        // The invariant is <=, so a full team is a LEGAL state, not an error.
        $this->assertFalse($this->seats()->overLimit($this->defaultTenant), 'At-limit is legal: the invariant is active <= limit.');
    }

    public function test_creating_an_active_user_beyond_the_limit_is_rejected(): void
    {
        $this->actingAsAdmin();
        $this->enforceSeatLimit(1); // the admin already occupies the only seat

        $this->submitNewUser('overflow@qds.test')
            ->assertHasErrors(['seats']);

        // The reserve() transaction rolled the insert back — no orphan row.
        $this->assertDatabaseMissing('users', ['email' => 'overflow@qds.test']);
        $this->assertSame(1, $this->defaultTenantSeats());
    }

    public function test_creating_an_inactive_user_beyond_the_limit_is_allowed(): void
    {
        $this->actingAsAdmin();
        $this->enforceSeatLimit(1); // full team

        // An inactive account consumes nothing, so a full team can still
        // pre-provision members (they will need a seat to activate later).
        $this->submitNewUser('benched@qds.test', active: false)
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'success');

        $created = User::query()->where('email', 'benched@qds.test')->sole();
        $this->assertFalse($created->active);
        $this->assertSame(1, $this->defaultTenantSeats(), 'The inactive hire consumed no seat.');
    }

    public function test_reactivation_via_edit_respects_the_seat_limit(): void
    {
        $this->actingAsAdmin();
        $subscription = $this->enforceSeatLimit(1); // admin fills the only seat

        $dormant = User::factory()->withRole(RoleName::Analyst)->inactive()->create();

        // Reactivating consumes a seat — with the team full it must fail
        // on the same 'seats' key the create path uses.
        Livewire::test(UsersIndex::class)
            ->call('edit', $dormant->id)
            ->set('active', true)
            ->call('save')
            ->assertHasErrors(['seats']);

        $this->assertFalse($dormant->fresh()->active, 'The blocked reactivation must not stick.');

        // Free a seat (upgrade via override) and retry: now it goes through.
        $subscription->update(['seats_override' => 2]);

        Livewire::test(UsersIndex::class)
            ->call('edit', $dormant->id)
            ->set('active', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'success');

        $this->assertTrue($dormant->fresh()->active);
        $this->assertSame(2, $this->defaultTenantSeats());
    }

    // ---------------------------------------------------------- bulk actions

    public function test_bulk_activation_is_all_or_nothing_and_deactivation_ignores_the_limit(): void
    {
        $admin = $this->actingAsAdmin();
        $subscription = $this->enforceSeatLimit(2); // admin uses 1 — ONE seat free

        $benchedA = User::factory()->inactive()->create();
        $benchedB = User::factory()->inactive()->create();

        // Two activations into one free seat: the batch overshoots, so the
        // whole reserve() transaction rolls back — including benchedA, who
        // would have fit on their own. Partial activation would make the
        // outcome depend on iteration order.
        Livewire::test(UsersIndex::class)
            ->set('selected', [(string) $benchedA->id, (string) $benchedB->id])
            ->call('bulkSetActive', true)
            ->assertDispatched('notify', type: 'error');

        $this->assertFalse($benchedA->fresh()->active, 'All-or-nothing: even the member who fit individually stays inactive.');
        $this->assertFalse($benchedB->fresh()->active);
        $this->assertSame(1, $this->defaultTenantSeats());

        // A batch that fits goes through.
        Livewire::test(UsersIndex::class)
            ->set('selected', [(string) $benchedA->id])
            ->call('bulkSetActive', true)
            ->assertDispatched('notify', type: 'success');

        $this->assertTrue($benchedA->fresh()->active);
        $this->assertSame(2, $this->defaultTenantSeats());

        // Downgrade to 1 seat: the team (2 active) is now over limit, yet
        // DEACTIVATION must still work — it is the recovery path.
        $subscription->update(['seats_override' => 1]);
        $this->assertTrue($this->seats()->overLimit($this->defaultTenant));

        Livewire::test(UsersIndex::class)
            ->set('selected', [(string) $benchedA->id])
            ->call('bulkSetActive', false)
            ->assertDispatched('notify', type: 'success');

        $this->assertFalse($benchedA->fresh()->active);
        $this->assertSame(1, $this->defaultTenantSeats());
        $this->assertTrue($admin->fresh()->active, 'The actor was never touched.');
    }

    // -------------------------------------------------- invitation acceptance

    public function test_acceptance_beyond_the_limit_is_refused_and_keeps_the_invitation_pending(): void
    {
        // Roles are needed even for the FAILING path: the accepter creates
        // the user + syncs the role BEFORE the recount throws and rolls back.
        $this->seedRoles();
        $this->enforceSeatLimit(2);

        $memberA = User::factory()->create();
        User::factory()->create(); // team full: 2 of 2

        [$invitation, $token] = $this->mintInvitation('invitee@qds.test', $memberA);

        $this->postAcceptance($token)
            ->assertOk()
            ->assertViewIs('billing.invitation-invalid')
            ->assertSee('no seat available');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'invitee@qds.test']);
        $this->assertSame(2, $this->defaultTenantSeats());

        // Crucially the invitation survives un-consumed: once a seat frees
        // up, the same link must still work.
        $invitation->refresh();
        $this->assertNull($invitation->accepted_at);
        $this->assertTrue($invitation->isPending(), 'A seat-blocked acceptance must not burn the single-use token.');
    }

    public function test_acceptance_of_the_last_free_seat_succeeds(): void
    {
        $this->seedRoles();
        $this->enforceSeatLimit(2);

        $member = User::factory()->create(); // 1 of 2 — exactly one seat free

        [$invitation, $token] = $this->mintInvitation('invitee@qds.test', $member);

        $this->postAcceptance($token)->assertRedirect(route('dashboard'));

        // The invitee proved mailbox control and set a password: signed in.
        $this->assertAuthenticated();

        $invitee = User::query()->where('email', 'invitee@qds.test')->sole();
        $this->assertTrue($invitee->active);
        $this->assertSame((int) $this->defaultTenant->id, (int) $invitee->tenant_id);
        $this->assertSame(RoleName::Analyst, $invitee->roleName(), 'The invited role (factory default) was assigned.');

        $invitation->refresh();
        $this->assertNotNull($invitation->accepted_at);
        $this->assertSame($invitee->id, $invitation->accepted_user_id);
        $this->assertSame(2, $this->defaultTenantSeats(), 'The acceptance consumed exactly the last seat.');
    }

    // ---------------------------------------------------------- downgrade rule

    public function test_downgrade_below_team_size_freezes_growth_until_members_fit_again(): void
    {
        Notification::fake();

        $this->actingAsAdmin();
        $subscription = $this->enforceSeatLimit(5);

        $analystA = User::factory()->withRole(RoleName::Analyst)->create();
        User::factory()->withRole(RoleName::Analyst)->create();
        $dormant = User::factory()->withRole(RoleName::Analyst)->inactive()->create();
        // Active members: admin + 2 analysts = 3 (dormant consumes nothing).

        $rosterBefore = User::query()->count();

        // The plan shrinks under the team (Stripe-side downgrade mirrored
        // onto the row): 3 active on a 2-seat plan.
        $subscription->update([
            'subscription_plan_id' => SubscriptionPlan::factory()->seats(2)->create()->id,
        ]);

        $this->assertTrue($this->seats()->overLimit($this->defaultTenant));

        // 1) Inviting is frozen (validation error, no invitation row).
        Livewire::test(TeamInvitationsPanel::class)
            ->set('email', 'frozen-out@qds.test')
            ->set('role', RoleName::Analyst->value)
            ->call('invite')
            ->assertHasErrors(['email']);

        $this->assertDatabaseMissing('team_invitations', ['email' => 'frozen-out@qds.test']);

        // 2) Reactivation is frozen.
        Livewire::test(UsersIndex::class)
            ->call('edit', $dormant->id)
            ->set('active', true)
            ->call('save')
            ->assertHasErrors(['seats']);

        $this->assertFalse($dormant->fresh()->active);

        // 3) Creating an ACTIVE user is frozen.
        $this->submitNewUser('fifth-wheel@qds.test')->assertHasErrors(['seats']);
        $this->assertDatabaseMissing('users', ['email' => 'fifth-wheel@qds.test']);

        // 4) Deactivation is the sanctioned way out — always allowed.
        Livewire::test(UsersIndex::class)
            ->set('selected', [(string) $analystA->id])
            ->call('bulkSetActive', false)
            ->assertDispatched('notify', type: 'success');

        $this->assertFalse($analystA->fresh()->active);
        $this->assertSame(2, $this->defaultTenantSeats());
        $this->assertFalse($this->seats()->overLimit($this->defaultTenant), 'Back at the limit — the freeze lifts.');

        // 5) With the team fitting again, inviting works (pending
        //    invitations still consume nothing, so at-limit inviting is fine).
        Livewire::test(TeamInvitationsPanel::class)
            ->set('email', 'welcome-back@qds.test')
            ->set('role', RoleName::Analyst->value)
            ->call('invite')
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'success');

        $this->assertDatabaseHas('team_invitations', ['email' => 'welcome-back@qds.test']);
        Notification::assertSentOnDemand(
            TeamInvitationNotification::class,
            fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'welcome-back@qds.test'
        );

        // The downgrade never cost anyone their account — deactivation, not
        // deletion, is the only automatic consequence surface (and even that
        // was operator-initiated here).
        $this->assertSame($rosterBefore, User::query()->count(), 'A downgrade must never auto-delete user rows.');
    }

    // ------------------------------------------------------- enforcement off

    public function test_enforcement_off_leaves_creation_and_acceptance_unlimited(): void
    {
        $this->seedRoles();

        // The shipped default — explicit so this test still documents the
        // rollout state if the suite ever flips the global default.
        config(['billing.enforced' => false]);
        $this->activeSubscription(1); // a tiny plan the team immediately exceeds

        $admin = $this->makeUser(RoleName::Admin); // 1 active — plan already full

        // Guest acceptance past the plan size sails through.
        [, $token] = $this->mintInvitation('unlimited@qds.test', $admin);
        $this->postAcceptance($token)->assertRedirect(route('dashboard'));

        $this->assertTrue(User::query()->where('email', 'unlimited@qds.test')->sole()->active);

        // Admin-side creation past the plan size too — no 'seats' error exists.
        $this->actingAs($admin);
        $this->submitNewUser('third-member@qds.test')
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'success');

        $this->assertTrue(User::query()->where('email', 'third-member@qds.test')->sole()->active);
        $this->assertSame(3, $this->defaultTenantSeats(), 'Three active members on a 1-seat plan: OFF means unlimited.');
        $this->assertNull($this->seats()->limitFor($this->defaultTenant));
    }
}
