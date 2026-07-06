<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Modules\CRM\Models\Campaign;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * ENT-Campaign is a Module 3 CRM record (ownership matrix; M1 reads for
 * reporting): staff read it with crm.view and write it with crm.manage.
 * The fine-grained per-role matrix is later P3 scope (REQ-M3-012);
 * CLIENT_VIEWER holds neither.
 */
class CampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::CRM_VIEW);
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return $user->can(PermissionsCatalog::CRM_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }
}
