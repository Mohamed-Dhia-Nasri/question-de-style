<?php

namespace App\Modules\Billing\Services;

use App\Models\User;
use App\Modules\Billing\Exceptions\SeatLimitExceeded;
use App\Modules\Billing\Models\TeamInvitation;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Invitation acceptance (ADR-0021) — the seat-guarded account-creation
 * path for invited staff.
 *
 * Runs from a GUEST request (platform context): the invitation is located
 * by token hash (the token IS the credential — unscoped lookup is correct),
 * then everything mutating runs under runAs(invitation tenant) inside
 * SeatLimiter::reserve(), which holds the tenant's seat lock. Single-use is
 * enforced by re-loading the invitation FOR UPDATE inside that transaction
 * and re-checking pending state — a replayed or concurrent acceptance of
 * the same token serializes behind the lock and fails cleanly.
 *
 * Email policy: the account is created with the INVITED email — acceptance
 * never takes an email input, so the intended-recipient binding cannot be
 * tampered with. users.email is globally unique (ADR-0019): an email already
 * registered on ANY tenant cannot accept (membership compatibility — one
 * user belongs to exactly one tenant).
 */
class TeamInvitationAccepter
{
    public function __construct(
        private readonly SeatLimiter $seats,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** Resolve a plaintext token to its invitation, valid or not. */
    public function find(string $plaintextToken): ?TeamInvitation
    {
        return TeamInvitation::query()
            ->withoutGlobalScopes()
            ->where('token_hash', TeamInvitation::hashToken($plaintextToken))
            ->first();
    }

    /**
     * @param  array{display_name: string, password: string}  $account
     *
     * @throws ValidationException when the invitation is no longer acceptable or the email is taken
     * @throws SeatLimitExceeded when no seat is available
     *
     * Both roll the whole transaction back.
     */
    public function accept(TeamInvitation $invitation, array $account): User
    {
        $tenantId = (int) $invitation->tenant_id;

        return $this->seats->reserve($tenantId, function () use ($invitation, $account, $tenantId): User {
            // Re-load under the seat lock: single-use + expiry + revocation
            // are decided HERE, atomically, not at the form render.
            /** @var TeamInvitation|null $fresh */
            $fresh = TeamInvitation::query()
                ->withoutGlobalScopes()
                ->whereKey($invitation->id)
                ->lockForUpdate()
                ->first();

            if ($fresh === null || ! $fresh->isPending()) {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation is no longer valid.',
                ]);
            }

            // Membership compatibility: the login identity is global.
            // Case-insensitive — users.email is stored verbatim elsewhere
            // (UsersIndex does not lower-case), and Postgres text equality
            // is case-sensitive, so a mixed-case existing account must
            // still block acceptance of its lower-cased invitation.
            $emailTaken = User::query()
                ->withoutGlobalScopes()
                ->whereRaw('lower(email) = ?', [strtolower($fresh->email)])
                ->exists();

            if ($emailTaken) {
                throw ValidationException::withMessages([
                    'invitation' => 'An account with this email address already exists.',
                ]);
            }

            return $this->context->runAs($tenantId, function () use ($fresh, $account): User {
                $user = User::create([
                    'display_name' => $account['display_name'],
                    'email' => $fresh->email,
                    'password' => $account['password'],
                    'active' => true,
                ]);

                // Exactly one role per user: syncRoles, never assignRole.
                $user->syncRoles([$fresh->role->value]);

                $fresh->forceFill([
                    'accepted_at' => now(),
                    'accepted_user_id' => $user->id,
                ])->save();

                // Guest flow: Auth::id() is null — keep the actor traceable.
                $this->audit->record('team.invitation.accepted', $fresh, [
                    'accepted_user_id' => $user->id,
                    'role' => $fresh->role->value,
                ]);

                return $user;
            });
        });
    }
}
