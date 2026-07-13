<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant context for web requests: authenticated user →
 * that user's tenant. Guests (login, password reset) run in platform
 * context; every authenticated surface downstream — Livewire components,
 * services, policies, dispatched jobs — resolves the same TenantContext.
 */
class SetTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->tenant_id !== null) {
            $this->context->setId((int) $user->tenant_id);
        }

        return $next($request);
    }
}
