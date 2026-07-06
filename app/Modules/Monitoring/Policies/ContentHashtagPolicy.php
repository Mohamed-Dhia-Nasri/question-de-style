<?php

namespace App\Modules\Monitoring\Policies;

use App\Models\User;
use App\Modules\Monitoring\Models\ContentHashtag;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * Extracted hashtags are written by SVC-EnrichmentAI; users never create
 * or delete them. Update is the DP-004 ambiguity-resolution path and
 * requires monitoring.manage. Staff read with monitoring.view.
 */
class ContentHashtagPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function view(User $user, ContentHashtag $contentHashtag): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ContentHashtag $contentHashtag): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_MANAGE);
    }

    public function delete(User $user, ContentHashtag $contentHashtag): bool
    {
        return false;
    }
}
