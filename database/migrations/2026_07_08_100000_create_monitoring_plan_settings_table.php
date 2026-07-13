<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-chosen monitoring plan (single-row settings table): polling
 * frequencies and the Apify plan tier used for cost estimates. Product-
 * owner decision 2026-07-08: the agency chooses its own cost/freshness
 * trade-off in-app instead of via env — DB-backed so changes need no
 * deploy. Config values remain the fallback defaults when no row exists.
 * Operational infrastructure, not an ENT-* (data-model "operational
 * registers" section).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_plan_settings', function (Blueprint $table) {
            $table->id();
            // Content-poll spacing for creators NOT attached to a running
            // campaign/seeding run (hours between polls; <=6 = every cycle).
            $table->unsignedSmallInteger('baseline_content_interval_hours');
            // Content-poll spacing for campaign-attached creators.
            $table->unsignedSmallInteger('campaign_content_interval_hours');
            // Story polls per day (0 = off). Bounded by the story cycle
            // cron's slots (default every 4h = max 6).
            $table->unsignedTinyInteger('stories_per_day');
            // Profile-poll spacing (hours).
            $table->unsignedSmallInteger('profile_poll_interval_hours');
            // Apify plan tier used for cost estimation only (FREE/STARTER/
            // SCALE/BUSINESS) — informational, never gates behavior.
            $table->string('apify_plan', 20);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_plan_settings');
    }
};
