<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request correlation id: honour an inbound X-Request-Id (e.g. from nginx),
 * otherwise generate one; attach it to the log context and echo it on the
 * response so a user-reported error can be traced through the logs.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();

        // Whatever arrives from outside is untrusted input: constrain it.
        $requestId = substr(preg_replace('/[^A-Za-z0-9\-_.]/', '', $requestId) ?: (string) Str::uuid(), 0, 64);

        $request->attributes->set('request_id', $requestId);

        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
