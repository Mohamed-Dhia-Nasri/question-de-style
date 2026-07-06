<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Modules\CRM\Models\Product;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * ENT-Product is a Module 3 CRM record (ownership matrix): staff read it
 * with crm.view and write it with crm.manage. The fine-grained per-role
 * matrix is later P3 scope (REQ-M3-012); CLIENT_VIEWER holds neither.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::CRM_VIEW);
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can(PermissionsCatalog::CRM_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }
}
