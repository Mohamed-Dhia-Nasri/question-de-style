<?php

namespace Tests\Feature\Settings;

use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\RoleName;
use Tests\TestCase;

class ReachPermissionsTest extends TestCase
{
    public function test_grants_settings_view_to_all_staff_and_reach_manage_to_admin_only(): void
    {
        $assignments = PermissionsCatalog::roleAssignments();
        $this->assertContains(PermissionsCatalog::SETTINGS_VIEW, $assignments[RoleName::Analyst->value]);
        $this->assertNotContains(PermissionsCatalog::REACH_MANAGE, $assignments[RoleName::Analyst->value]);
        $this->assertContains(PermissionsCatalog::REACH_MANAGE, $assignments[RoleName::Admin->value]);
        $this->assertNotContains(PermissionsCatalog::SETTINGS_VIEW, $assignments[RoleName::ClientViewer->value]);
        $this->assertContains(PermissionsCatalog::SETTINGS_VIEW, PermissionsCatalog::all());
        $this->assertContains(PermissionsCatalog::REACH_MANAGE, PermissionsCatalog::all());
    }
}
