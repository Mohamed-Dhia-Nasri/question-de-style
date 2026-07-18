<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * ENT-User writes are restricted to ADMIN (ownership matrix; REQ-M3-012
 * AC-M3-018: a non-ADMIN attempting to write User/Role is denied). Expressed
 * through the users.manage permission, which only the ADMIN role holds.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::USERS_MANAGE);
    }

    public function view(User $user, User $model): bool
    {
        return $user->can(PermissionsCatalog::USERS_MANAGE);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsCatalog::USERS_MANAGE);
    }

    public function update(User $user, User $model): bool
    {
        return $user->can(PermissionsCatalog::USERS_MANAGE);
    }

    public function delete(User $user, User $model): bool
    {
        // No self-deletion (an admin cannot remove their own account) and
        // never the tenant's billing owner: tenants.owner_user_id is a
        // RESTRICT foreign key, so the delete would fail at the database with
        // an unhandled 500. Ownership must be reassigned first.
        return $user->can(PermissionsCatalog::USERS_MANAGE)
            && ! $user->is($model)
            && ! $model->isTenantOwner();
    }
}
