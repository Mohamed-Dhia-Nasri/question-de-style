<?php

namespace Tests;

use App\Models\User;
use App\Shared\Enums\RoleName;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /** Seed canonical roles/permissions and clear the spatie cache. */
    protected function seedRoles(): void
    {
        $this->seed(RolePermissionSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function makeUser(RoleName $role, array $attributes = []): User
    {
        return User::factory()->withRole($role)->create($attributes);
    }
}
