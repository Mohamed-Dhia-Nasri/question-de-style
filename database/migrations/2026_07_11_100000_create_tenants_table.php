<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ENT-Tenant (ADR-0019) — the customer account owning all business data.
 *
 * owner_user_id is nullable only because of the tenant↔user chicken-and-egg
 * (a user needs a tenant_id, so the tenant row must exist first); the
 * application invariant — every tenant has exactly one owner who belongs to
 * that tenant — is enforced by TenantProvisioner and covered by tests.
 * The FK is deliberately NO ACTION: the owner cannot be deleted without
 * transferring ownership first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_user_id')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
