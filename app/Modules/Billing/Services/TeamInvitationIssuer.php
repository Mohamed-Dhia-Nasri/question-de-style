<?php

namespace App\Modules\Billing\Services;

use App\Models\User;
use App\Modules\Billing\Models\TeamInvitation;
use App\Modules\Billing\Notifications\TeamInvitationNotification;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\RoleName;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Issues and revokes team invitations (ADR-0021).
 *
 * Runs in the inviter's request context: the invitation row is stamped
 * with the active tenant by BelongsToTenant, and the inviter comes from
 * the authenticated user — never from input. Validation (email shape, no
 * existing user, no duplicate pending invite, staff-only role) happens in
 * the Livewire component; the DB partial-unique index and the composite
 * tenant FKs back-stop it.
 */
class TeamInvitationIssuer
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function issue(User $inviter, string $email, RoleName $role): TeamInvitation
    {
        // 256 bits of entropy; the plaintext exists only in this scope and
        // the invitation email. The row stores the SHA-256 hash.
        $token = Str::random(64);

        $invitation = new TeamInvitation([
            'email' => $email,
            'role' => $role,
            'expires_at' => now()->addDays((int) config('billing.invitation_expiry_days')),
        ]);

        // Trusted server state — force-filled, never mass assigned.
        $invitation->forceFill([
            'token_hash' => TeamInvitation::hashToken($token),
            'invited_by_user_id' => $inviter->id,
        ]);

        $invitation->save();

        $this->audit->record('team.invitation.created', $invitation, [
            'role' => $role->value,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);

        Notification::route('mail', $email)
            ->notify(new TeamInvitationNotification($invitation, $token));

        return $invitation;
    }

    public function revoke(User $actor, TeamInvitation $invitation): void
    {
        $invitation->forceFill(['revoked_at' => now()])->save();

        $this->audit->record('team.invitation.revoked', $invitation);
    }
}
