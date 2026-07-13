<?php

namespace App\Modules\Billing\Policies;

use App\Models\User;
use App\Modules\Billing\Models\TeamInvitation;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * Team invitations are user administration (ADR-0021): the same ADMIN-only
 * users.manage permission that gates ENT-User writes (AC-M3-018). Tenant
 * ownership is decided first by the Gate::before TenantIsolationGate —
 * this policy stays permission-only (ADR-0020 §1).
 */
class TeamInvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::USERS_MANAGE);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsCatalog::USERS_MANAGE);
    }

    public function delete(User $user, TeamInvitation $invitation): bool
    {
        return $user->can(PermissionsCatalog::USERS_MANAGE);
    }
}
