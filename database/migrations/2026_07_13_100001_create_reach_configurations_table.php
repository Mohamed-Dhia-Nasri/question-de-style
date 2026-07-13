<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-tenant versioned reach-estimation configuration (REQ-M1-006,
     * MET-EstimatedReach, ADR-0022). Mirrors emv_configurations: DRAFT →
     * ACTIVE lifecycle, at most one ACTIVE per tenant, append-only history
     * so past estimated-reach values stay reproducible. Born tenant-aware
     * (ADR-0019/0020): NOT NULL tenant_id, UNIQUE(id, tenant_id) so a later
     * reach_results table can compose-FK to it, composite (col, tenant_id)
     * FKs on the audit-stamp columns.
     */
    public function up(): void
    {
        Schema::create('reach_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('name');
            $table->string('method');
            $table->string('formula_version', 64);
            $table->jsonb('params');
            $table->date('effective_from');
            $table->string('status', 10)->default('DRAFT')->index();
            $table->text('notes')->nullable();
            $table->jsonb('assumptions')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('tenant_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE reach_configurations ADD CONSTRAINT reach_configurations_status_check
                CHECK (status IN ('DRAFT','ACTIVE','INACTIVE','ARCHIVED'))
        SQL);

        // Composite-parent target so a later reach_results table can
        // FK (reach_configuration_id, tenant_id) → (id, tenant_id).
        DB::statement('ALTER TABLE reach_configurations ADD CONSTRAINT reach_configurations_id_tenant_unique UNIQUE (id, tenant_id)');

        // Version namespace + one-ACTIVE rule are per tenant.
        DB::statement('ALTER TABLE reach_configurations ADD CONSTRAINT reach_configurations_tenant_version_unique UNIQUE (tenant_id, formula_version)');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX reach_configurations_one_active_index
                ON reach_configurations (tenant_id) WHERE status = 'ACTIVE'
        SQL);

        // Audit-stamp users must belong to the owning tenant (composite FK).
        DB::statement('ALTER TABLE reach_configurations ADD CONSTRAINT reach_configurations_created_by_tenant_fk FOREIGN KEY (created_by, tenant_id) REFERENCES users (id, tenant_id)');
        DB::statement('ALTER TABLE reach_configurations ADD CONSTRAINT reach_configurations_activated_by_tenant_fk FOREIGN KEY (activated_by, tenant_id) REFERENCES users (id, tenant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reach_configurations');
    }
};
