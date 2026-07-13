<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every user belongs to exactly one tenant (ADR-0019).
 *
 * BACKFILL ASSUMPTION (explicit, not silent): a database that already has
 * users predates multi-tenancy and therefore contains exactly one implicit
 * customer — the original agency. All existing users (and, in the follow-up
 * migrations, all existing business rows) are assigned to a single founding
 * tenant named "Question de Style" created here. Its owner is set to the
 * earliest active ADMIN user, if one exists. Fresh installs skip the
 * backfill entirely (no users → no founding tenant).
 *
 * users.email stays GLOBALLY unique: one user belongs to one tenant, and
 * the email is the login identity across the platform (confirmed against
 * the Fortify email-credential flow before keeping this constraint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->index();
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        if (DB::table('users')->exists()) {
            $foundingTenantId = DB::table('tenants')->insertGetId([
                'name' => 'Question de Style',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('users')->whereNull('tenant_id')->update(['tenant_id' => $foundingTenantId]);

            $ownerId = DB::table('users')
                ->join('model_has_roles', function ($join): void {
                    $join->on('model_has_roles.model_id', '=', 'users.id')
                        ->where('model_has_roles.model_type', '=', User::class);
                })
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', 'ADMIN')
                ->where('users.active', true)
                ->orderBy('users.id')
                ->value('users.id');

            if ($ownerId !== null) {
                DB::table('tenants')
                    ->where('id', $foundingTenantId)
                    ->update(['owner_user_id' => $ownerId, 'updated_at' => now()]);
            }
        }

        DB::statement('ALTER TABLE users ALTER COLUMN tenant_id SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
