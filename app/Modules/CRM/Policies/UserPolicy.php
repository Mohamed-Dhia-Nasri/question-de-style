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
        // No self-deletion: an admin cannot remove their own account.
        return $user->can(PermissionsCatalog::USERS_MANAGE)
            && ! $user->is($model);
    }
}
