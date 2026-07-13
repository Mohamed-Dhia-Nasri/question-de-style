<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ENT-TeamInvitation (ADR-0021) — secure, tenant-bound team invitations:
 * docs/30-data-model/00-data-model.md#ent-teaminvitation.
 *
 * Tenant-owned (NOT NULL tenant_id). The invitation token is a bearer
 * credential: only its SHA-256 hash is stored (the plaintext travels once,
 * in the invitation email), so a database leak can never be replayed into
 * account creation. Single-use + expiry + tenant binding are enforced at
 * acceptance inside the seat-reserving transaction (TeamInvitationAccepter).
 *
 * Composite tenant FKs to users (ADR-0019 §3): the inviter and the accepted
 * user must belong to the invitation's tenant — cross-tenant links are
 * rejected by the database itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants');
            $table->string('email');
            // A staff ENUM-RoleName value (never CLIENT_VIEWER — ADR-0016
            // keeps external client accounts out of v1).
            $table->string('role', 40);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by_user_id')->constrained('users');
            $table->foreignId('accepted_user_id')->nullable()->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // Cross-tenant-link prevention (MATCH SIMPLE skips the accepted_user
        // NULLs while the invitation is pending).
        DB::statement(
            'ALTER TABLE team_invitations ADD CONSTRAINT team_invitations_invited_by_user_id_tenant_fk '
            .'FOREIGN KEY (invited_by_user_id, tenant_id) REFERENCES users (id, tenant_id)'
        );
        DB::statement(
            'ALTER TABLE team_invitations ADD CONSTRAINT team_invitations_accepted_user_id_tenant_fk '
            .'FOREIGN KEY (accepted_user_id, tenant_id) REFERENCES users (id, tenant_id)'
        );

        // One PENDING invitation per (tenant, email) — accepted/revoked
        // history never blocks re-inviting.
        DB::statement(
            'CREATE UNIQUE INDEX team_invitations_pending_email_unique '
            .'ON team_invitations (tenant_id, lower(email)) WHERE accepted_at IS NULL AND revoked_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invitations');
    }
};
