<?php

namespace Tests\Feature\Settings;

use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Tests\TestCase;

class MonitoringSettingsPermissionsTest extends TestCase
{
    public function test_manage_is_admin_only_and_view_stays_staff_wide(): void
    {
        $assignments = PermissionsCatalog::roleAssignments();
        $this->assertContains(PermissionsCatalog::SETTINGS_VIEW, $assignments[RoleName::Analyst->value]);
        $this->assertNotContains(PermissionsCatalog::MONITORING_SETTINGS_MANAGE, $assignments[RoleName::Analyst->value]);
        $this->assertContains(PermissionsCatalog::MONITORING_SETTINGS_MANAGE, $assignments[RoleName::Admin->value]);
        $this->assertNotContains(PermissionsCatalog::MONITORING_SETTINGS_MANAGE, $assignments[RoleName::ClientViewer->value]);
        $this->assertContains(PermissionsCatalog::MONITORING_SETTINGS_MANAGE, PermissionsCatalog::all());
    }
}
