<?php

namespace App\Modules\Monitoring\Policies;

use App\Models\User;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * ENT-SentimentAnalysis is written by SVC-EnrichmentAI (ownership matrix).
 * Update is the DP-004 analyst-correction path (AC-M1-010) and requires
 * monitoring.manage; create/delete are never user actions.
 */
class SentimentAnalysisPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function view(User $user, SentimentAnalysis $sentimentAnalysis): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SentimentAnalysis $sentimentAnalysis): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_MANAGE);
    }

    public function delete(User $user, SentimentAnalysis $sentimentAnalysis): bool
    {
        return false;
    }
}
