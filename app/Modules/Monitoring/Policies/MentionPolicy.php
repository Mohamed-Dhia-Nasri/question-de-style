<?php

namespace App\Modules\Monitoring\Policies;

use App\Models\User;
use App\Modules\Monitoring\Models\Mention;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * ENT-Mention is written by SVC-EnrichmentAI (ownership matrix); users never
 * create or delete detections. Staff read with monitoring.view; update is
 * the DP-004 human-review correction path (AC-M1-002) and requires
 * monitoring.manage.
 */
class MentionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function view(User $user, Mention $mention): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Mention $mention): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_MANAGE);
    }

    public function delete(User $user, Mention $mention): bool
    {
        return false;
    }
}
