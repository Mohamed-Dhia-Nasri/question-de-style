<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * (id, tenant_id) unique on keyframes — the composite-FK anchor the
     * keyframe_embeddings table (sub-project C, ADR-0029) needs: children
     * FK (keyframe_id, tenant_id) → keyframes (id, tenant_id) per the
     * reach_results tenant-FK pattern (ADR-0019/0020). Redundant with the
     * PK for lookups; it exists solely so Postgres accepts the composite
     * FK — the pattern every other tenant-owned parent already follows.
     */
    public function up(): void
    {
        Schema::table('keyframes', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::table('keyframes', function (Blueprint $table): void {
            $table->dropUnique(['id', 'tenant_id']);
        });
    }
};
