<?php

namespace Database\Seeders;

use App\Shared\Authorization\PermissionsCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Central seeder for the canonical roles (ENUM-RoleName) and the application
 * permission catalog. Idempotent — safe to re-run on every deploy.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (PermissionsCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (PermissionsCatalog::roleAssignments() as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($permissions);
        }
    }
}
