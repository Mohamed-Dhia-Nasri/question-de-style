<?php

namespace App\Platform\Ingestion\Observability\Policies;

use App\Models\User;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * Redacted provider response samples are debugging material for authorized
 * technical users ONLY (External API Monitoring security requirement).
 * Gated on audit administration — the narrowest existing documented
 * permission (ADMIN-only per PermissionsCatalog); never CLIENT_VIEWER.
 */
class ProviderResponseSamplePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::AUDIT_VIEW);
    }

    public function view(User $user): bool
    {
        return $user->can(PermissionsCatalog::AUDIT_VIEW);
    }
}
