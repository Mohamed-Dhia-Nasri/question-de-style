<?php

namespace App\Shared\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Password-reset link failures are normalized to the SAME response as success
 * so an unknown email is indistinguishable from a known one (M33). Otherwise
 * the endpoint is a user-enumeration oracle: the default failure flashes an
 * "we can't find a user" error while success flashes a status. Field-format
 * validation (email required|email) still runs earlier and keeps its own
 * error — only the "no such user" broker outcome is masked here.
 */
class GenericPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    public function toResponse($request): Response
    {
        $message = trans('passwords.sent');

        if ($request->wantsJson()) {
            return new JsonResponse(['message' => $message], 200);
        }

        return back()->with('status', $message);
    }
}
