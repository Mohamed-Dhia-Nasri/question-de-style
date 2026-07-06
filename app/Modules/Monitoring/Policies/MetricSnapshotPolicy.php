<?php

namespace App\Modules\Monitoring\Policies;

use App\Models\User;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * ENT-MetricSnapshot is append-only history written solely by
 * SVC-SnapshotScheduler (ADR-0003, ownership matrix). No user may ever
 * create, update, or delete a snapshot — not even ADMIN. Staff read access
 * follows monitoring.view.
 */
class MetricSnapshotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function view(User $user, MetricSnapshot $metricSnapshot): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_VIEW);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, MetricSnapshot $metricSnapshot): bool
    {
        return false;
    }

    public function delete(User $user, MetricSnapshot $metricSnapshot): bool
    {
        return false;
    }
}
