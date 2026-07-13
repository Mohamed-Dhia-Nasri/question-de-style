<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Exceptions\SeatLimitExceeded;
use App\Modules\Billing\Services\TeamInvitationAccepter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Guest invitation acceptance (ADR-0021) — the reset-password/{token}
 * shape: a token-bearing guest route rendering a Blade form.
 *
 * The GET is a courtesy render (friendly errors for stale links); every
 * security decision — single-use, expiry, revocation, email-taken, seat
 * availability — is re-made atomically inside TeamInvitationAccepter under
 * the tenant's seat lock at POST time.
 */
class InvitationAcceptController
{
    public function show(string $token, TeamInvitationAccepter $accepter): View
    {
        // A signed-in session cannot accept: users belong to exactly one
        // tenant and invitations create NEW accounts (ADR-0021).
        if (Auth::check()) {
            return $this->invalid('You are already signed in. Sign out first to accept an invitation.');
        }

        $invitation = $accepter->find($token);

        if ($invitation === null || ! $invitation->isPending()) {
            return $this->invalid('This invitation link is invalid, expired, or has already been used.');
        }

        return view('billing.invitation-accept', [
            'invitation' => $invitation,
            'token' => $token,
        ]);
    }

    public function store(Request $request, string $token, TeamInvitationAccepter $accepter): RedirectResponse|View
    {
        if (Auth::check()) {
            return $this->invalid('You are already signed in. Sign out first to accept an invitation.');
        }

        $invitation = $accepter->find($token);

        if ($invitation === null || ! $invitation->isPending()) {
            return $this->invalid('This invitation link is invalid, expired, or has already been used.');
        }

        /** @var array{display_name: string, password: string} $account */
        $account = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            // The staff password policy (UsersIndex/ResetUserPassword).
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        try {
            $user = $accepter->accept($invitation, $account);
        } catch (SeatLimitExceeded) {
            return $this->invalid(
                'This team has no seat available right now. Ask the workspace owner to free a seat or upgrade the plan, then try again.'
            );
        } catch (ValidationException $e) {
            return $this->invalid(collect($e->errors())->flatten()->first() ?? 'This invitation can no longer be accepted.');
        }

        // The invitee just proved control of the invited mailbox and set
        // their password — sign them in directly.
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    private function invalid(string $reason): View
    {
        return view('billing.invitation-invalid', ['reason' => $reason]);
    }
}
