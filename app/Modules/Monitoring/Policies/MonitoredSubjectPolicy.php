<?php

namespace App\Modules\Monitoring\Policies;

use App\Models\User;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * ENT-MonitoredSubject is Module 1's own configuration record (ownership
 * matrix): staff read it with monitoring.view and manage the roster
 * (ADR-0011) with monitoring.manage.
 */
class MonitoredSubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function view(User $user, MonitoredSubject $monitoredSubject): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_MANAGE);
    }

    public function update(User $user, MonitoredSubject $monitoredSubject): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_MANAGE);
    }

    public function delete(User $user, MonitoredSubject $monitoredSubject): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_MANAGE);
    }
}
