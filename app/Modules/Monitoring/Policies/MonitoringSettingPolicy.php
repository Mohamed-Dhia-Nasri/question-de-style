<?php

namespace App\Modules\Monitoring\Policies;

use App\Models\User;
use App\Modules\Monitoring\Models\MonitoringSetting;
use App\Shared\Authorization\PermissionsCatalog;

/**
 * Monitoring settings (ADR-0025): staff may read them on the Settings page
 * (settings.view); only holders of monitoring-settings.manage (ADMIN) may
 * save. Rows are append-only history — never edited or deleted.
 */
class MonitoringSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsCatalog::SETTINGS_VIEW);
    }

    public function view(User $user, MonitoringSetting $setting): bool
    {
        return $user->can(PermissionsCatalog::SETTINGS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsCatalog::MONITORING_SETTINGS_MANAGE);
    }

    public function update(User $user, MonitoringSetting $setting): bool
    {
        return false;
    }

    public function delete(User $user, MonitoringSetting $setting): bool
    {
        return false;
    }
}
