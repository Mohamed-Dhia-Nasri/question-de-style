<?php

namespace App\Modules\Monitoring\Policies;

use App\Models\User;
use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * EMV configurations (REQ-M1-011): staff may read them (AC-M1-011 —
 * every EMV surface discloses the model and rates) via the Settings page
 * (settings.view), but only holders of emv.manage (ADMIN) may create,
 * edit, activate, deactivate, or archive. Rows are never hard-deleted:
 * historical versions keep past results reproducible — "delete" is the
 * ARCHIVED status.
 */
class EmvConfigurationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::SETTINGS_VIEW);
    }

    public function view(User $user, EmvConfiguration $configuration): bool
    {
        return $user->can(PermissionsCatalog::SETTINGS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsCatalog::EMV_MANAGE);
    }

    public function update(User $user, EmvConfiguration $configuration): bool
    {
        return $user->can(PermissionsCatalog::EMV_MANAGE);
    }

    public function delete(User $user, EmvConfiguration $configuration): bool
    {
        return false;
    }
}
