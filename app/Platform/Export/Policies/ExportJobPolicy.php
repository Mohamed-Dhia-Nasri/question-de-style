<?php

namespace App\Platform\Export\Policies;

use App\Models\User;
use App\Platform\Export\Models\ExportJob;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * Exports are internal staff artifacts (REQ-M1-012). CLIENT_VIEWER holds
 * no export permission — client-facing approved reports are the separate
 * P3 surface (REQ-M3-012) and never this job ledger.
 *
 * Download is owner-only (plus ADMIN): an export can embed exactly the
 * slice its requester was authorized to see, so jobs are not shared
 * between staff members.
 */
class ExportJobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::EXPORTS_CREATE);
    }

    public function view(User $user, ExportJob $job): bool
    {
        return $user->can(PermissionsCatalog::EXPORTS_CREATE)
            && ($job->user_id === $user->id || $user->can(PermissionsCatalog::AUDIT_VIEW));
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsCatalog::EXPORTS_CREATE);
    }

    public function download(User $user, ExportJob $job): bool
    {
        return $this->view($user, $job);
    }
}
