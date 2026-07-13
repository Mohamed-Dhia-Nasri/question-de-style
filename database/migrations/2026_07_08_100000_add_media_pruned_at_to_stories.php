<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media storage lifecycle (P4 hardening, DP-005): records when a story's
 * archived media file was deleted by retention pruning, so the persister
 * never re-queues archival for media that was intentionally removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->timestamp('media_pruned_at')->nullable()->after('media_url');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn('media_pruned_at');
        });
    }
};
