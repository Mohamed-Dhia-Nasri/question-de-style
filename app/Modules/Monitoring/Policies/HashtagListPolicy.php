<?php

namespace App\Modules\Monitoring\Policies;

use App\Models\User;
use App\Modules\Monitoring\Models\HashtagList;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * Configured hashtag lists (campaign/brand/product/agency) are Module 1
 * monitoring configuration, managed by staff with monitoring.manage —
 * the same surface as roster configuration. Never CLIENT_VIEWER.
 */
class HashtagListPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function view(User $user, HashtagList $hashtagList): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_MANAGE);
    }

    public function update(User $user, HashtagList $hashtagList): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_MANAGE);
    }

    public function delete(User $user, HashtagList $hashtagList): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_MANAGE);
    }
}
