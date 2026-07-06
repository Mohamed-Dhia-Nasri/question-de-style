<?php

namespace App\Modules\Monitoring\Policies;

use App\Models\User;
use App\Modules\Monitoring\Models\Story;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * ENT-Story is archived exclusively by the Module 1 ingestion pipeline
 * before platform expiry (REQ-M1-004, ownership matrix) — never
 * user-written. Staff read access follows monitoring.view.
 */
class StoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function view(User $user, Story $story): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Story $story): bool
    {
        return false;
    }

    public function delete(User $user, Story $story): bool
    {
        return false;
    }
}
