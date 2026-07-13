<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Billing\Livewire\TeamInvitationsPanel;
use App\Modules\Billing\Models\TeamInvitation;
use App\Modules\Billing\Notifications\TeamInvitationNotification;
use App\Shared\Enums\RoleName;
use Database\Factories\TeamInvitationFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Team invitation flow end to end (ADR-0021): issuing from the admin panel
 * (hashed single-use token, on-demand email), invite validation (no existing
 * user, no duplicate pending, staff roles only), revocation, and the guest
 * accept flow (GET render + POST account creation bound to the INVITING
 * tenant, single-use under the seat lock, replay/expiry/revocation/taken-email
 * all rejected without consuming the invitation).
 *
 * billing.enforced stays at its default (false) throughout — invitations
 * work regardless of enforcement. The seat-full acceptance path is covered
 * in SeatEnforcementTest and deliberately not duplicated here.
 */
class TeamInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $this->seedRoles();

        $admin = $this->makeUser(RoleName::Admin, ['display_name' => 'Panel Admin']);
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * Create an invitation whose plaintext token is known to the test — the
     * factory alone cannot drive the accept flow (only the hash is stored).
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: TeamInvitation, 1: string}
     */
    private function mintInvitation(?TeamInvitationFactory $factory = null, array $attributes = []): array
    {
        $token = Str::random(64);

        $invitation = ($factory ?? TeamInvitation::factory())
            ->create($attributes + ['token_hash' => TeamInvitation::hashToken($token)]);

        return [$invitation, $token];
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function acceptPayload(array $overrides = []): array
    {
        return $overrides + [
            'display_name' => 'Invited Member',
            'password' => 'correct-horse-battery-12',
            'password_confirmation' => 'correct-horse-battery-12',
        ];
    }

    public function test_admin_issues_an_invitation_with_hashed_token_and_email_notification(): void
    {
        Notification::fake();
        $admin = $this->actingAsAdmin();

        Livewire::test(TeamInvitationsPanel::class)
            ->set('email', 'New.Member@QDS.test')
            ->set('role', RoleName::CampaignManager->value)
            ->call('invite')
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'success');

        $invitation = TeamInvitation::query()->sole();

        // Address normalized; tenant and inviter stamped from server state,
        // never from input.
        $this->assertSame('new.member@qds.test', $invitation->email);
        $this->assertSame($this->defaultTenant->id, $invitation->tenant_id);
        $this->assertSame($admin->id, $invitation->invited_by_user_id);
        $this->assertSame(RoleName::CampaignManager, $invitation->role);
        $this->assertTrue($invitation->isPending());

        // The row stores a SHA-256 digest (64 hex chars), never the token.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $invitation->token_hash);

        Notification::assertSentOnDemand(
            TeamInvitationNotification::class,
            function (TeamInvitationNotification $notification, array $channels, object $notifiable) use ($invitation): bool {
                // The mailed link carries the ONLY plaintext copy of the
                // token; its hash must be the stored lookup key, and the
                // plaintext must differ from what the database holds.
                $mailedToken = Str::afterLast($notification->toMail($notifiable)->actionUrl, '/');

                return $notifiable->routes['mail'] === 'new.member@qds.test'
                    && $mailedToken !== $invitation->token_hash
                    && TeamInvitation::hashToken($mailedToken) === $invitation->token_hash;
            }
        );
    }

    public function test_invite_does_not_reveal_that_an_email_exists_in_another_tenant(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        // users.email is globally unique (ADR-0019). Inviting an address that
        // already belongs to ANOTHER tenant must NOT surface a distinguishable
        // "already taken" error — that would be a cross-tenant account-existence
        // oracle (Class-20). The caller sees the same generic outcome as a fresh
        // address, no invitation is created, and no mail is sent to the account.
        $elsewhere = $this->makeTenant('Elsewhere Agency');
        $this->withTenant($elsewhere, fn (): User => User::factory()->create(['email' => 'taken@qds.test']));

        Livewire::test(TeamInvitationsPanel::class)
            ->set('email', 'taken@qds.test')
            ->set('role', RoleName::Analyst->value)
            ->call('invite')
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'success');

        $this->assertDatabaseMissing('team_invitations', ['email' => 'taken@qds.test']);
        Notification::assertNothingSent();
    }

    public function test_invite_silently_ignores_a_duplicate_pending_invitation(): void
    {
        Notification::fake();
        $admin = $this->actingAsAdmin();

        TeamInvitation::factory()->create([
            'email' => 'dup@qds.test',
            'invited_by_user_id' => $admin->id,
        ]);

        // A second invite for an address that already has a PENDING invitation
        // in this tenant is a no-op with the same generic outcome — no duplicate
        // row (the partial-unique index would reject it), no error that would
        // confirm the first invitation's existence, no second email.
        Livewire::test(TeamInvitationsPanel::class)
            ->set('email', 'dup@qds.test')
            ->set('role', RoleName::Analyst->value)
            ->call('invite')
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'success');

        $this->assertSame(1, TeamInvitation::query()->where('email', 'dup@qds.test')->count());
        Notification::assertNothingSent();
    }

    public function test_invite_outcome_is_indistinguishable_for_fresh_and_cross_tenant_emails(): void
    {
        Notification::fake();
        $this->actingAsAdmin();

        // Close the enumeration oracle end to end: the visible result of
        // inviting an address that already exists on ANOTHER tenant must be
        // byte-identical to inviting a genuinely fresh address, so a
        // users.manage admin cannot probe platform-wide account existence.
        $genericMessage = 'If this address can be invited, an invitation has been sent.';

        $elsewhere = $this->makeTenant('Elsewhere Agency');
        $this->withTenant($elsewhere, fn (): User => User::factory()->create(['email' => 'exists-elsewhere@qds.test']));

        Livewire::test(TeamInvitationsPanel::class)
            ->set('email', 'exists-elsewhere@qds.test')
            ->set('role', RoleName::Analyst->value)
            ->call('invite')
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'success', message: $genericMessage);

        Livewire::test(TeamInvitationsPanel::class)
            ->set('email', 'brand-new@qds.test')
            ->set('role', RoleName::Analyst->value)
            ->call('invite')
            ->assertHasNoErrors()
            ->assertDispatched('notify', type: 'success', message: $genericMessage);

        // Only the genuinely fresh address produced an invitation + notification.
        $this->assertDatabaseMissing('team_invitations', ['email' => 'exists-elsewhere@qds.test']);
        $this->assertDatabaseHas('team_invitations', ['email' => 'brand-new@qds.test']);
        Notification::assertSentOnDemand(TeamInvitationNotification::class);
    }

    public function test_invite_rejects_client_viewer_and_unknown_roles(): void
    {
        $this->actingAsAdmin();

        $component = Livewire::test(TeamInvitationsPanel::class);

        // CLIENT_VIEWER is a real role but not staff (ADR-0016); the second
        // value is plain garbage — both must die on the role rule.
        foreach ([RoleName::ClientViewer->value, 'SUPERADMIN'] as $role) {
            $component
                ->set('email', 'viewer@qds.test')
                ->set('role', $role)
                ->call('invite')
                ->assertHasErrors(['role']);
        }

        $this->assertDatabaseCount('team_invitations', 0);
    }

    public function test_an_expired_invitation_for_the_same_email_is_revoked_and_replaced(): void
    {
        Notification::fake();
        $admin = $this->actingAsAdmin();

        $expired = TeamInvitation::factory()->expired()->create([
            'email' => 'retry@qds.test',
            'invited_by_user_id' => $admin->id,
        ]);

        Livewire::test(TeamInvitationsPanel::class)
            ->set('email', 'retry@qds.test')
            ->set('role', RoleName::Analyst->value)
            ->call('invite')
            ->assertHasNoErrors();

        // The stale row is revoked (audited), not silently deleted — the DB
        // partial unique index requires it before the replacement can exist.
        $this->assertNotNull($expired->refresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'team.invitation.revoked',
            'subject_id' => $expired->id,
        ]);

        $fresh = TeamInvitation::query()
            ->where('email', 'retry@qds.test')
            ->whereNull('revoked_at')
            ->sole();

        $this->assertNotSame($expired->id, $fresh->id);
        $this->assertTrue($fresh->isPending());

        Notification::assertSentOnDemand(
            TeamInvitationNotification::class,
            fn (TeamInvitationNotification $n, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'retry@qds.test'
        );
    }

    public function test_a_pending_invitation_can_be_revoked(): void
    {
        $admin = $this->actingAsAdmin();

        $invitation = TeamInvitation::factory()->create(['invited_by_user_id' => $admin->id]);

        Livewire::test(TeamInvitationsPanel::class)
            ->call('revoke', $invitation->id)
            ->assertDispatched('notify', type: 'success');

        $this->assertNotNull($invitation->refresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'team.invitation.revoked',
            'subject_id' => $invitation->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_an_accepted_invitation_cannot_be_revoked(): void
    {
        $admin = $this->actingAsAdmin();
        $member = User::factory()->create();

        $invitation = TeamInvitation::factory()->create([
            'invited_by_user_id' => $admin->id,
            'accepted_at' => now()->subDay(),
            'accepted_user_id' => $member->id,
        ]);

        Livewire::test(TeamInvitationsPanel::class)
            ->call('revoke', $invitation->id)
            ->assertDispatched('notify', type: 'error');

        // The consumed row is immutable: no revocation stamp, no audit event.
        $invitation->refresh();
        $this->assertNull($invitation->revoked_at);
        $this->assertNotNull($invitation->accepted_at);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'team.invitation.revoked',
            'subject_id' => $invitation->id,
        ]);
    }

    public function test_show_renders_the_accept_form_for_a_valid_pending_token(): void
    {
        [, $token] = $this->mintInvitation(attributes: ['email' => 'render-check@qds.test']);

        $this->get(route('invitations.show', ['token' => $token]))
            ->assertOk()
            ->assertViewIs('billing.invitation-accept')
            // The invitee sees which workspace they are joining, and the
            // address the account will be created under.
            ->assertSee('Default Test Tenant')
            ->assertSee('render-check@qds.test');
    }

    public function test_show_renders_the_invalid_view_for_dead_tokens(): void
    {
        [, $expiredToken] = $this->mintInvitation(TeamInvitation::factory()->expired());
        [, $revokedToken] = $this->mintInvitation(TeamInvitation::factory()->revoked());

        $deadTokens = [
            'garbage' => 'definitely-not-a-token',
            'expired' => $expiredToken,
            'revoked' => $revokedToken,
        ];

        foreach ($deadTokens as $case => $token) {
            $response = $this->get(route('invitations.show', ['token' => $token]));

            $response->assertOk();
            $this->assertSame(
                'billing.invitation-invalid',
                $response->original->name(),
                "A {$case} token must render the invalid view."
            );
        }
    }

    public function test_accepting_a_valid_invitation_creates_the_user_and_signs_them_in(): void
    {
        $this->seedRoles();

        [$invitation, $token] = $this->mintInvitation(
            TeamInvitation::factory()->role(RoleName::CampaignManager),
            ['email' => 'newbie@qds.test'],
        );

        $this->post(route('invitations.accept', ['token' => $token]), $this->acceptPayload())
            ->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'newbie@qds.test')->sole();

        $this->assertSame($this->defaultTenant->id, $user->tenant_id);
        $this->assertTrue($user->active);
        // Exactly one role, the invited one — syncRoles, never assignRole.
        $this->assertSame([RoleName::CampaignManager->value], $user->roles()->pluck('name')->all());

        $invitation->refresh();
        $this->assertNotNull($invitation->accepted_at);
        $this->assertSame($user->id, $invitation->accepted_user_id);

        // The invitee proved mailbox control and set a password — signed in.
        $this->assertAuthenticatedAs($user);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'team.invitation.accepted',
            'subject_id' => $invitation->id,
        ]);
    }

    public function test_a_consumed_token_cannot_be_replayed(): void
    {
        $this->seedRoles();

        [, $token] = $this->mintInvitation(attributes: ['email' => 'once@qds.test']);

        $this->post(route('invitations.accept', ['token' => $token]), $this->acceptPayload())
            ->assertRedirect(route('dashboard'));

        // The same link replayed from a fresh, signed-out browser.
        Auth::logout();

        $this->post(route('invitations.accept', ['token' => $token]), $this->acceptPayload(['display_name' => 'Imposter']))
            ->assertOk()
            ->assertViewIs('billing.invitation-invalid');

        $this->assertSame(
            1,
            User::withoutGlobalScopes()->where('email', 'once@qds.test')->count(),
            'A replayed token must not mint a second account.'
        );
    }

    public function test_dead_tokens_cannot_be_accepted(): void
    {
        [, $expiredToken] = $this->mintInvitation(TeamInvitation::factory()->expired(), ['email' => 'late@qds.test']);
        [, $revokedToken] = $this->mintInvitation(TeamInvitation::factory()->revoked(), ['email' => 'gone@qds.test']);

        // Snapshot AFTER minting (each factory row also creates an inviter).
        $usersBefore = User::withoutGlobalScopes()->count();

        $deadTokens = [
            'expired' => $expiredToken,
            'revoked' => $revokedToken,
            'garbage' => 'definitely-not-a-token',
        ];

        foreach ($deadTokens as $case => $token) {
            $response = $this->post(route('invitations.accept', ['token' => $token]), $this->acceptPayload());

            $response->assertOk();
            $this->assertSame(
                'billing.invitation-invalid',
                $response->original->name(),
                "A {$case} token POST must render the invalid view."
            );
        }

        $this->assertSame(
            $usersBefore,
            User::withoutGlobalScopes()->count(),
            'No account may be created through a dead token.'
        );
    }

    public function test_an_email_registered_after_issuance_blocks_acceptance_without_consuming_the_invitation(): void
    {
        [$invitation, $token] = $this->mintInvitation(attributes: ['email' => 'raced@qds.test']);

        // The address gets registered between issuance and acceptance —
        // the login identity is global, so acceptance must fail.
        User::factory()->create(['email' => 'raced@qds.test']);

        $this->post(route('invitations.accept', ['token' => $token]), $this->acceptPayload())
            ->assertOk()
            ->assertViewIs('billing.invitation-invalid')
            ->assertSee('already exists');

        // Not consumed: the transaction rolled back, the row stays pending.
        $invitation->refresh();
        $this->assertNull($invitation->accepted_at);
        $this->assertTrue($invitation->isPending());
        $this->assertSame(1, User::withoutGlobalScopes()->where('email', 'raced@qds.test')->count());
    }

    public function test_acceptance_email_taken_check_is_case_insensitive(): void
    {
        // ADR-0021 adversarial finding: UsersIndex stores emails verbatim
        // (mixed case allowed) and Postgres text equality is case-sensitive.
        // An existing 'Foo@qds.test' account must still block acceptance of
        // the lower-cased 'foo@qds.test' invitation — no duplicate identity.
        User::factory()->create(['email' => 'Foo@qds.test']);

        [$invitation, $token] = $this->mintInvitation(attributes: ['email' => 'foo@qds.test']);

        $this->post(route('invitations.accept', ['token' => $token]), $this->acceptPayload())
            ->assertOk()
            ->assertViewIs('billing.invitation-invalid')
            ->assertSee('already exists');

        $this->assertTrue($invitation->refresh()->isPending(), 'A case-variant collision must not consume the invitation.');
        $this->assertSame(1, User::withoutGlobalScopes()->whereRaw('lower(email) = ?', ['foo@qds.test'])->count());
    }

    public function test_signed_in_users_cannot_view_or_accept_invitations(): void
    {
        $this->actingAsAdmin();

        [$invitation, $token] = $this->mintInvitation(attributes: ['email' => 'fresh@qds.test']);

        // Users belong to exactly one tenant: an authenticated session must
        // be told to sign out, on both the render and the accept.
        $this->get(route('invitations.show', ['token' => $token]))
            ->assertOk()
            ->assertViewIs('billing.invitation-invalid')
            ->assertSee('Sign out first');

        $this->post(route('invitations.accept', ['token' => $token]), $this->acceptPayload())
            ->assertOk()
            ->assertViewIs('billing.invitation-invalid');

        $this->assertTrue($invitation->refresh()->isPending(), 'A signed-in hit must not consume the invitation.');
        $this->assertSame(0, User::withoutGlobalScopes()->where('email', 'fresh@qds.test')->count());
    }

    public function test_acceptance_binds_the_user_to_the_inviting_tenant_not_the_ambient_context(): void
    {
        [$tenantA, $tenantB] = $this->makeTenantPair();

        [, $token] = $this->withTenant($tenantA, fn (): array => $this->mintInvitation(attributes: [
            'email' => 'bound@qds.test',
            'invited_by_user_id' => $tenantA->owner_user_id,
        ]));

        // Simulate the token leaking to "someone of" tenant B: the ambient
        // context points at B, and the guest request itself is tenant-less.
        $this->actingAsTenant($tenantB);

        $this->post(route('invitations.accept', ['token' => $token]), $this->acceptPayload())
            ->assertRedirect(route('dashboard'));

        $user = User::withoutGlobalScopes()->where('email', 'bound@qds.test')->sole();

        $this->assertSame($tenantA->id, $user->tenant_id, 'The invitation row, not ambient context, decides the tenant.');
        $this->assertNotSame($tenantB->id, $user->tenant_id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_foreign_tenant_admin_cannot_revoke_another_tenants_invitation(): void
    {
        [$tenantA, $tenantB] = $this->makeTenantPair();

        $invitationA = $this->withTenant($tenantA, fn (): TeamInvitation => TeamInvitation::factory()->create([
            'email' => 'target@qds.test',
            'invited_by_user_id' => $tenantA->owner_user_id,
        ]));

        $ownerB = User::withoutGlobalScopes()->findOrFail($tenantB->owner_user_id);
        $this->actingAsTenant($tenantB);
        $this->actingAs($ownerB);

        // Tenant-scoped findOrFail: the foreign id is invisible (404), the
        // revocation never even reaches authorization.
        $this->expectException(ModelNotFoundException::class);

        Livewire::test(TeamInvitationsPanel::class)->call('revoke', $invitationA->id);
    }

    public function test_the_password_policy_is_enforced_at_acceptance(): void
    {
        [$invitation, $token] = $this->mintInvitation(attributes: ['email' => 'strict@qds.test']);

        // 10 chars — below the 12-char staff policy.
        $this->from(route('invitations.show', ['token' => $token]))
            ->post(route('invitations.accept', ['token' => $token]), $this->acceptPayload([
                'password' => 'short-pw-1',
                'password_confirmation' => 'short-pw-1',
            ]))
            ->assertRedirect(route('invitations.show', ['token' => $token]))
            ->assertSessionHasErrors('password');

        $this->assertSame(0, User::withoutGlobalScopes()->where('email', 'strict@qds.test')->count());
        $this->assertTrue($invitation->refresh()->isPending(), 'A rejected password must not consume the invitation.');
    }
}
