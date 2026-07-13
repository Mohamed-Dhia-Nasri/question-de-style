<?php

namespace App\Modules\Billing\Livewire;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Billing\Models\TeamInvitation;
use App\Modules\Billing\Services\SeatLimiter;
use App\Modules\Billing\Services\TeamInvitationIssuer;
use App\Shared\Enums\RoleName;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Team invitations panel (ADR-0021) — lives on the admin users page: the
 * existing UsersIndex stays the member list / role / activation surface
 * (no duplicate team UI), this panel adds seat visibility plus the
 * invite/revoke flow. Gated on the same users.manage permission.
 */
class TeamInvitationsPanel extends Component
{
    public string $email = '';

    public string $role = '';

    public function mount(): void
    {
        $this->authorize('viewAny', TeamInvitation::class);
    }

    public function invite(TeamInvitationIssuer $issuer, SeatLimiter $seats): void
    {
        $this->authorize('create', TeamInvitation::class);

        $validated = $this->validate([
            // NO unique() rule on purpose: a cross-tenant email collision must
            // not surface a distinguishable "already taken" error, which would
            // be a platform-wide account-existence oracle (Class-20). Global
            // uniqueness is still enforced by the users.email DB constraint and
            // re-checked atomically at acceptance (TeamInvitationAccepter).
            'email' => ['required', 'string', 'email', 'max:255'],
            // Staff roles only: ADR-0016 keeps CLIENT_VIEWER accounts out
            // of v1, and invitations must not become the loophole.
            'role' => ['required', Rule::in(array_column(RoleName::staff(), 'value'))],
        ]);

        $email = strtolower($validated['email']);
        $tenant = $this->tenant();

        // Downgrade rule (ADR-0021): an over-limit team makes no further
        // team changes until active members fit the limit again. This reveals
        // only the actor's OWN tenant seat posture — safe to surface.
        if ($seats->overLimit($tenant)) {
            throw ValidationException::withMessages([
                'email' => 'The team is over its seat limit — deactivate members or upgrade before inviting.',
            ]);
        }

        $this->issueIfInvitable($issuer, $email, RoleName::from($validated['role']));

        $this->reset('email', 'role');
        $this->resetValidation();

        // Uniform, non-enumerable outcome: whether the address was freshly
        // invited, already belongs to an account (this or ANY other tenant),
        // or already had a pending invite, the caller sees the SAME result —
        // no cross-tenant existence signal leaks.
        $this->dispatch('notify', type: 'success', message: 'If this address can be invited, an invitation has been sent.');
    }

    /**
     * Issue an invitation ONLY when the address is genuinely invitable, and
     * without revealing to the caller which non-invitable case applied:
     *  - already a platform account (any tenant): a new invitation could never
     *    be accepted (acceptance blocks taken emails), so skip silently;
     *  - already has a pending invitation in THIS tenant: don't duplicate (the
     *    DB partial-unique index would reject it anyway);
     *  - otherwise: revoke any expired leftovers and send a fresh invitation.
     */
    private function issueIfInvitable(TeamInvitationIssuer $issuer, string $email, RoleName $role): void
    {
        // Global, case-insensitive existence check ($email is already lowered).
        // Unscoped by design — the login identity spans tenants (ADR-0019).
        $emailTaken = User::query()
            ->withoutGlobalScopes()
            ->whereRaw('lower(email) = ?', [$email])
            ->exists();

        if ($emailTaken) {
            return;
        }

        // Tenant-scoped by the global scope: only THIS tenant's invitations.
        // One PENDING invitation per address; expired leftovers are replaced.
        $existing = TeamInvitation::query()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->get();

        if ($existing->contains(fn (TeamInvitation $invitation): bool => $invitation->isPending())) {
            return;
        }

        foreach ($existing as $expired) {
            $issuer->revoke(auth()->user(), $expired);
        }

        $issuer->issue(auth()->user(), $email, $role);
    }

    public function revoke(int $invitationId, TeamInvitationIssuer $issuer): void
    {
        // Tenant-scoped binding: a foreign-tenant id 404s here, and the
        // TenantIsolationGate backstops the authorize call.
        $invitation = TeamInvitation::query()->findOrFail($invitationId);

        $this->authorize('delete', $invitation);

        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            $this->dispatch('notify', type: 'error', message: 'This invitation can no longer be revoked.');

            return;
        }

        $issuer->revoke(auth()->user(), $invitation);

        $this->dispatch('notify', type: 'success', message: 'Invitation revoked.');
    }

    public function render(SeatLimiter $seats): View
    {
        $tenant = $this->tenant();
        $subscription = $tenant->currentSubscription();

        return view('livewire.billing.team-invitations', [
            'invitations' => TeamInvitation::query()
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->with('invitedBy')
                ->latest()
                ->get(),
            'roles' => RoleName::staff(),
            'seatsUsed' => $seats->activeSeats((int) $tenant->id),
            'seatLimit' => $subscription?->seatLimit(),
            'overLimit' => $seats->overLimit($tenant),
        ]);
    }

    private function tenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = auth()->user()->tenant;

        return $tenant;
    }
}
