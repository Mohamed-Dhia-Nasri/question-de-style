<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Modules\CRM\Models\PlatformAccount;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * ENT-PlatformAccount is Module 3 CRM's record (ownership matrix): staff
 * read it with crm.view and write it with crm.manage. Writes route through
 * SVC-CRM (CreatorWriter for identity, IngestedProfileSync for polled
 * public profile fields) — this policy governs which staff may trigger
 * them. CLIENT_VIEWER holds neither.
 */
class PlatformAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::CRM_VIEW);
    }

    public function view(User $user, PlatformAccount $platformAccount): bool
    {
        return $user->can(PermissionsCatalog::CRM_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }

    public function update(User $user, PlatformAccount $platformAccount): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }

    public function delete(User $user, PlatformAccount $platformAccount): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }
}
