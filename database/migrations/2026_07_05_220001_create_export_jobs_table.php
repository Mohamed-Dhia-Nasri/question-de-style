<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SVC-Export job ledger (REQ-M1-012, AC-M1-012).
     *
     * FLAGGED DEVIATION: not a canonical ENT-* — an operational table of the
     * export service (same class as the ingestion/enrichment operational
     * tables), awaiting a doc amendment.
     *
     * Rules encoded here:
     *  - artifacts live on a PRIVATE disk under a random name; access is
     *    only via a short-lived signed URL after a policy check — never a
     *    public URL;
     *  - files expire (expires_at) and are deleted by the prune command;
     *  - duplicate prevention: at most one PENDING/RUNNING job per user ×
     *    report × format × filter-set (partial unique index);
     *  - `filters` holds the validated dashboard filter set the report was
     *    built with (identifiers and enum codes only — no personal data);
     *  - `error` is sanitized (message class only, never payloads).
     */
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained();
            $table->string('report', 60);
            $table->string('format', 10);
            $table->jsonb('filters');
            $table->string('filters_hash', 64);
            $table->string('status', 10)->default('PENDING')->index();
            $table->string('disk', 30)->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('correlation_id', 64)->index();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        // ENUM-ExportFormat — closed set (glossary).
        DB::statement(<<<'SQL'
            ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_format_check
                CHECK (format IN ('PDF','EXCEL','CSV'))
        SQL);

        // Internal operational vocabulary — not a canonical ENUM-*.
        DB::statement(<<<'SQL'
            ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_status_check
                CHECK (status IN ('PENDING','RUNNING','COMPLETED','FAILED','EXPIRED'))
        SQL);

        // Duplicate-job prevention (one live job per identical request).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX export_jobs_live_unique
                ON export_jobs (user_id, report, format, filters_hash)
                WHERE status IN ('PENDING','RUNNING')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
