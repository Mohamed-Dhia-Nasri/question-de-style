<?php

namespace App\Modules\Monitoring\Policies;

use App\Models\User;
use App\Modules\Monitoring\Models\ContentItem;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * ENT-ContentItem is written exclusively by the Module 1 ingestion pipeline
 * (ownership matrix) — no user ever creates, edits, or deletes ingested
 * public content, so those abilities are denied outright. Staff read access
 * follows monitoring.view.
 */
class ContentItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function view(User $user, ContentItem $contentItem): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ContentItem $contentItem): bool
    {
        return false;
    }

    public function delete(User $user, ContentItem $contentItem): bool
    {
        return false;
    }
}
