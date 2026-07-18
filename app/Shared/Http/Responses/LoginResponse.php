<?php

namespace App\Shared\Http\Responses;

use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role-aware post-login redirect: CLIENT_VIEWER users land on the approved
 * reports area (the only surface they may access, REQ-M3-012); staff land on
 * the internal dashboard.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        /** @var Request $request */
        $user = $request->user();

        // CLIENT_VIEWER may reach ONLY the approved-reports area (REQ-M3-012).
        // Ignore any intended URL — it can be a staff-only 403 surface stashed
        // by the guest-bounce (e.g. a deep link to /dashboard) — and send them
        // straight to their reports area (M32).
        if ($user !== null && $user->isClientViewer()) {
            return redirect()->route('reports.index');
        }

        return redirect()->intended(route('dashboard'));
    }
}
