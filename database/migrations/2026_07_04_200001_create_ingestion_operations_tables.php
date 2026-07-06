<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operational infrastructure for SVC-Ingestion's External API
     * Monitoring — telemetry, health, alerts, quarantine, response
     * sampling, and monitoring-cycle bookkeeping. These are NOT domain
     * ENT-* tables (no doc entity defines them); they hold sanitized
     * operational metadata only and are FLAGGED for a documentation
     * amendment (operational-infrastructure register).
     */
    public function up(): void
    {
        // One row per external provider call: timing breakdown, HTTP
        // status, record accounting, rate-limit state, sanitized error.
        Schema::create('provider_calls', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64);
            $table->string('operation', 48);
            $table->string('correlation_id', 64);
            $table->string('job_id', 64)->nullable();
            $table->unsignedBigInteger('platform_account_id')->nullable()->index();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->float('duration_ms')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('outcome', 10);
            $table->string('error_category', 30)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->unsignedBigInteger('response_bytes')->nullable();
            $table->unsignedInteger('result_count')->nullable();
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('quarantined_count')->default(0);
            $table->jsonb('rate_limit')->nullable();
            $table->jsonb('timings')->nullable();
            $table->timestamp('created_at');

            $table->index(['source', 'started_at']);
            $table->index(['source', 'outcome', 'started_at']);
            $table->index('correlation_id');
        });

        // Current health per SRC-* provider (one row per provider).
        Schema::create('provider_health_states', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64)->unique();
            $table->string('status', 10);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->string('last_error_category', 30)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();
        });

        // Deduplicated alerts: one OPEN row per (type, source) fingerprint.
        Schema::create('ingestion_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('alert_type', 30);
            $table->string('source', 64)->nullable();
            $table->string('fingerprint', 40);
            $table->string('severity', 10)->default('warning');
            $table->text('message');
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('first_occurred_at');
            $table->timestamp('last_occurred_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['fingerprint', 'resolved_at']);
            $table->index(['alert_type', 'resolved_at']);
        });

        // Invalid provider records held out of domain tables. Payload is
        // REDACTED before insert; rows expire with the retention window.
        Schema::create('quarantined_records', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64);
            $table->string('operation', 48);
            $table->string('correlation_id', 64);
            $table->string('external_hint')->nullable();
            $table->string('reason_category', 30);
            $table->text('reason');
            $table->jsonb('payload');
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at');

            $table->index(['source', 'created_at']);
        });

        // Short-retention, REDACTED response samples for debugging;
        // access restricted via ProviderResponseSamplePolicy.
        Schema::create('provider_response_samples', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64);
            $table->string('operation', 48);
            $table->string('correlation_id', 64);
            $table->jsonb('payload');
            $table->timestamp('sampled_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at');

            $table->index(['source', 'sampled_at']);
        });

        // Monitoring-cycle bookkeeping: duplicate prevention + fan-out
        // accounting + total-cycle timing (AC-M1-001).
        Schema::create('ingestion_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('correlation_id', 64)->unique();
            $table->string('status', 10);
            $table->boolean('stories_only')->default(false);
            $table->unsignedInteger('accounts_count')->default(0);
            $table->unsignedInteger('jobs_expected')->default(0);
            $table->unsignedInteger('jobs_pending')->default(0);
            $table->unsignedInteger('jobs_failed')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_cycles');
        Schema::dropIfExists('provider_response_samples');
        Schema::dropIfExists('quarantined_records');
        Schema::dropIfExists('ingestion_alerts');
        Schema::dropIfExists('provider_health_states');
        Schema::dropIfExists('provider_calls');
    }
};
