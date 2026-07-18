<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate-limits the Fortify password-reset endpoints (M33). Fortify registers
 * the /forgot-password and /reset-password POST routes with no throttle and
 * reads no limiter config for them, so an attacker could sweep the
 * enumeration/reset surface unchecked. This runs in the web group, after the
 * route is resolved, so it can key off the route name regardless of Fortify's
 * late route registration. Keyed by IP: 5 requests per minute.
 */
class ThrottlePasswordReset
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')
            || ! $request->routeIs('password.email', 'password.update')) {
            return $next($request);
        }

        $key = 'password-reset|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            abort(429, 'Too many password-reset attempts. Please try again later.');
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return $next($request);
    }
}
