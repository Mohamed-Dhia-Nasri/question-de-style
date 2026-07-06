<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Modules\CRM\Models\Contact;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * ENT-Contact is a Module 3 CRM record (ownership matrix), manual entry
 * only (REQ-M3-002): staff read it with crm.view and write it with
 * crm.manage. GDPR (DP-005): delete MUST remain possible — never gate the
 * hard-delete behind anything stricter than the manage permission.
 * CLIENT_VIEWER holds neither.
 */
class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::CRM_VIEW);
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->can(PermissionsCatalog::CRM_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $user->can(PermissionsCatalog::CRM_MANAGE);
    }
}
