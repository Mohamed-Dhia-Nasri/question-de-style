<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One calculated estimated-reach figure per ContentItem (REQ-M1-006,
     * ADR-0022). Append-only + reproducible, mirroring emv_results: each row
     * snapshots the reach configuration, formula version, inputs, and the
     * ReachEstimate envelope. Tenant-owned (ADR-0019/0020) with composite
     * (col, tenant_id) FKs to its content item and configuration.
     */
    public function up(): void
    {
        Schema::create('reach_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('content_item_id')->constrained();
            $table->foreignId('reach_configuration_id')->constrained();
            $table->string('formula_version', 64);
            $table->jsonb('value');
            $table->jsonb('inputs');
            $table->timestamp('calculated_at');
            $table->timestamp('created_at');

            $table->index('tenant_id');
            $table->index(['content_item_id', 'calculated_at']);
        });

        DB::statement('ALTER TABLE reach_results ADD CONSTRAINT reach_results_content_item_tenant_fk FOREIGN KEY (content_item_id, tenant_id) REFERENCES content_items (id, tenant_id)');
        DB::statement('ALTER TABLE reach_results ADD CONSTRAINT reach_results_reach_configuration_tenant_fk FOREIGN KEY (reach_configuration_id, tenant_id) REFERENCES reach_configurations (id, tenant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reach_results');
    }
};
