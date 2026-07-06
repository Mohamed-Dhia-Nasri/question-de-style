<?php

namespace App\Modules\Monitoring\Policies;

use App\Models\User;
use App\Modules\Monitoring\Models\ReviewAction;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * Review actions are the append-only DP-004 correction history, written
 * exclusively through the ReviewService. Staff may read the history;
 * nobody creates, edits, or deletes rows through user surfaces.
 */
class ReviewActionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function view(User $user, ReviewAction $reviewAction): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ReviewAction $reviewAction): bool
    {
        return false;
    }

    public function delete(User $user, ReviewAction $reviewAction): bool
    {
        return false;
    }
}
