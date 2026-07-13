<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full-depth marker for the periodic no-date-filter sweep (cost plan rec 1,
 * reviews/PLAN-apify-cost-optimization-2026-07-07.md): normal full cycles
 * send a provider-side refresh-window date filter; at most once per
 * qds.ingestion.full_sweep_interval_days one cycle runs full depth to catch
 * late-blooming engagement on posts older than the window. Persisted on the
 * cycle row so "when did the last sweep run" is a query, not guesswork.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingestion_cycles', function (Blueprint $table) {
            $table->boolean('full_depth')->default(false)->after('stories_only');
        });
    }

    public function down(): void
    {
        Schema::table('ingestion_cycles', function (Blueprint $table) {
            $table->dropColumn('full_depth');
        });
    }
};
