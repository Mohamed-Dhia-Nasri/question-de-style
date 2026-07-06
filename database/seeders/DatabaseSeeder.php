<?php

namespace Database\Seeders;

use App\Models\User;
use App\Shared\Enums\RoleName;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        // Convenience accounts for local development ONLY (never staging,
        // demo, or production — a known password must not exist on any
        // reachable deployment). Real users are provisioned by an ADMIN.
        if (app()->environment(['local', 'testing'])) {
            $admin = User::firstOrCreate(
                ['email' => 'admin@qds.test'],
                ['display_name' => 'QDS Admin', 'password' => 'password', 'active' => true],
            );
            $admin->syncRoles([RoleName::Admin->value]);

            $viewer = User::firstOrCreate(
                ['email' => 'client@qds.test'],
                ['display_name' => 'Client Viewer', 'password' => 'password', 'active' => true],
            );
            $viewer->syncRoles([RoleName::ClientViewer->value]);
        }
    }
}
