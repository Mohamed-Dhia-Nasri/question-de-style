<?php

namespace App\Modules\Monitoring\Policies;

use App\Models\User;
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * Reach configurations (REQ-M1-006, ADR-0022 pending): staff may read them
 * on the Settings page (settings.view); only holders of reach.manage (ADMIN)
 * may create/edit/activate/deactivate/archive. Never hard-deleted —
 * historical versions keep past estimated reach reproducible.
 */
class ReachConfigurationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::SETTINGS_VIEW);
    }

    public function view(User $user, ReachConfiguration $configuration): bool
    {
        return $user->can(PermissionsCatalog::SETTINGS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsCatalog::REACH_MANAGE);
    }

    public function update(User $user, ReachConfiguration $configuration): bool
    {
        return $user->can(PermissionsCatalog::REACH_MANAGE);
    }

    public function delete(User $user, ReachConfiguration $configuration): bool
    {
        return false;
    }
}
