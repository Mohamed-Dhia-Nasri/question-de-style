<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI budget governance (sub-project C spec §10; D reuses): capability-
     * keyed usage counters with atomic ON CONFLICT increments, and optional
     * per-tenant quota overrides (NULL column → config default).
     *
     * PLATFORM operational tables (ingestion_alerts precedent): tenant-
     * attributed via an explicit NOT NULL tenant_id but deliberately NOT
     * TenantScoped — the guard's GLOBAL budget dimensions aggregate across
     * every tenant, which a TenantScope would silently narrow inside
     * tenant-bound enrichment jobs. No children reference these tables, so
     * plain tenant FKs suffice (no composite-FK DDL needed). Monthly usage
     * = SUM over the month's daily rows; global = SUM across tenants. No
     * personal data — exempt from creator-GDPR erasure (spec §13); rows
     * age out with telemetry retention (qds:prune-ingestion-data).
     */
    public function up(): void
    {
        Schema::create('ai_usage_counters', function (Blueprint $table) {
            $table->id();
            $table->string('capability', 40);
            $table->foreignId('tenant_id')->constrained();
            $table->date('usage_date');
            $table->integer('units')->default(0);
            $table->bigInteger('estimated_cost_micro_usd')->default(0);
            $table->integer('posts_processed')->default(0);
            $table->integer('posts_skipped_budget')->default(0);
            $table->integer('posts_skipped_no_candidates')->default(0);
            $table->timestamp('updated_at');

            // The atomic upsert's conflict target.
            $table->unique(['capability', 'tenant_id', 'usage_date']);
            // Global dimension reads (SUM across tenants for a date range).
            $table->index(['capability', 'usage_date']);
        });

        Schema::create('tenant_ai_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('capability', 40);
            $table->integer('daily_units')->nullable();
            $table->integer('monthly_units')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'capability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_quotas');
        Schema::dropIfExists('ai_usage_counters');
    }
};
