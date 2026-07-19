<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table): void {
            // Null = tenant never chose → config default applies (ADR-0025
            // pattern); 0 = keep forever. No settings UI yet (sub-project B).
            $table->unsignedSmallInteger('keyframe_retention_days')->nullable()->after('story_retention_days');
        });

        DB::statement('ALTER TABLE monitoring_settings ADD CONSTRAINT monitoring_settings_keyframe_retention_check CHECK (keyframe_retention_days IS NULL OR keyframe_retention_days BETWEEN 0 AND 3650)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE monitoring_settings DROP CONSTRAINT IF EXISTS monitoring_settings_keyframe_retention_check');
        Schema::table('monitoring_settings', function (Blueprint $table): void {
            $table->dropColumn('keyframe_retention_days');
        });
    }
};
