<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Modules\CRM\Models\Creator;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * ENT-Creator is Module 3 CRM's system-of-record identity (ownership
 * matrix): staff read it with crm.view and write it with crm.manage.
 * ALL Creator writes additionally route through SVC-CRM (CreatorWriter /
 * XMC-001) — this policy governs which staff may trigger those writes,
 * never a bypass of the write path. CLIENT_VIEWER holds neither.
 */
class CreatorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::CRM_VIEW);
    }

    public function view(User $user, Creator $creator): bool
    {
        return $user->can(PermissionsCatalog::CRM_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }

    public function update(User $user, Creator $creator): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }

    public function delete(User $user, Creator $creator): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }
}
