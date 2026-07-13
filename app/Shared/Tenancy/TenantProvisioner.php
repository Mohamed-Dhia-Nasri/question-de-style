<?php

namespace App\Shared\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use App\Shared\Enums\RoleName;
use Illuminate\Support\Facades\DB;

/**
 * Creates a tenant together with its owner, atomically (ADR-0019).
 *
 * The owner is a regular user of the tenant holding the canonical ADMIN
 * role (ENUM-RoleName is closed — "owner" is not a role, it is the
 * tenants.owner_user_id attribute). Requires the canonical roles to be
 * seeded (RolePermissionSeeder) before use.
 */
final class TenantProvisioner
{
    /**
     * @param  array{display_name: string, email: string, password: string}  $owner
     */
    public function create(string $name, array $owner): Tenant
    {
        return DB::transaction(function () use ($name, $owner): Tenant {
            $tenant = Tenant::query()->create(['name' => $name]);

            $user = new User([
                'display_name' => $owner['display_name'],
                'email' => $owner['email'],
                'password' => $owner['password'],
                'active' => true,
            ]);
            $user->tenant_id = $tenant->id;
            $user->save();

            // Single-role rule: always syncRoles([...]), never assignRole().
            $user->syncRoles([RoleName::Admin->value]);

            $tenant->forceFill(['owner_user_id' => $user->id])->save();

            return $tenant->setRelation('owner', $user);
        });
    }
}
