<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope column for on-demand per-creator monitoring runs (operator "run
 * monitoring now"): NULL = a whole-roster scheduled cycle, set = a cycle
 * polling one creator's accounts only. Kept on the same table so the
 * fan-out bookkeeping, operations dashboard, and job counters are shared.
 * nullOnDelete: cycles are operational logs, not ENT-* history — a past
 * on-demand run must never block deleting the creator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingestion_cycles', function (Blueprint $table) {
            $table->foreignId('creator_id')
                ->nullable()
                ->after('stories_only')
                ->constrained()
                ->nullOnDelete();

            $table->index(['creator_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('ingestion_cycles', function (Blueprint $table) {
            $table->dropIndex(['creator_id', 'status']);
            $table->dropConstrainedForeignId('creator_id');
        });
    }
};
