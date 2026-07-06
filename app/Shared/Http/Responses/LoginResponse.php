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

        $home = $user !== null && $user->isClientViewer()
            ? route('reports.index')
            : route('dashboard');

        return redirect()->intended($home);
    }
}
