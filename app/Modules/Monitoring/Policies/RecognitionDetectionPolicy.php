<?php

namespace App\Modules\Monitoring\Policies;

use App\Models\User;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * ENT-RecognitionDetection is written by SVC-EnrichmentAI (ownership
 * matrix). Update is the DP-004 review-queue decision path (AC-M1-009) and
 * requires monitoring.manage; create/delete are never user actions.
 */
class RecognitionDetectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function view(User $user, RecognitionDetection $recognitionDetection): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, RecognitionDetection $recognitionDetection): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_MANAGE);
    }

    public function delete(User $user, RecognitionDetection $recognitionDetection): bool
    {
        return false;
    }
}
