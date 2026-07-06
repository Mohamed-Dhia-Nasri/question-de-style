<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Modules\CRM\Models\CommunicationLog;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * ENT-CommunicationLog is a Module 3 CRM record (ownership matrix): staff
 * read it with crm.view and write it with crm.manage. The fine-grained
 * per-role matrix is later P3 scope (REQ-M3-012); CLIENT_VIEWER holds
 * neither.
 */
class CommunicationLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::CRM_VIEW);
    }

    public function view(User $user, CommunicationLog $communicationLog): bool
    {
        return $user->can(PermissionsCatalog::CRM_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }

    public function update(User $user, CommunicationLog $communicationLog): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }

    public function delete(User $user, CommunicationLog $communicationLog): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }
}
