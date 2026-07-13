<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Billing\Services\SubscriptionPlanSync;
use App\Shared\Enums\RoleName;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        // Plan catalog (ADR-0021): global configuration rows, synced
        // idempotently from config/billing.php — safe for every
        // environment (commercial values come from env, never code).
        app(SubscriptionPlanSync::class)->sync();

        // Convenience accounts for local development ONLY (never staging,
        // demo, or production — a known password must not exist on any
        // reachable deployment). Real users are provisioned by an ADMIN.
        if (app()->environment(['local', 'testing'])) {
            // The dev tenant mirrors the founding tenant created by the
            // tenancy backfill migration on pre-existing databases.
            $tenant = Tenant::firstOrCreate(['name' => 'Question de Style']);

            $admin = User::firstOrCreate(
                ['email' => 'admin@qds.test'],
                [
                    'display_name' => 'QDS Admin',
                    'password' => 'password',
                    'active' => true,
                    'tenant_id' => $tenant->id,
                ],
            );
            $admin->syncRoles([RoleName::Admin->value]);

            if ($tenant->owner_user_id === null) {
                $tenant->forceFill(['owner_user_id' => $admin->id])->save();
            }

            $viewer = User::firstOrCreate(
                ['email' => 'client@qds.test'],
                [
                    'display_name' => 'Client Viewer',
                    'password' => 'password',
                    'active' => true,
                    'tenant_id' => $tenant->id,
                ],
            );
            $viewer->syncRoles([RoleName::ClientViewer->value]);
        }
    }
}
