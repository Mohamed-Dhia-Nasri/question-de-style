<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0019 hard-enforcement: give ingestion_alerts an OPTIONAL tenant owner.
 *
 * ingestion_alerts stays platform telemetry (provider-level incidents —
 * repeated failures, schema drift, stale data — are genuinely global and
 * keep tenant_id NULL). But the P4 data-quality scan embeds tenant creator
 * handles into metric-anomaly / snapshot-gap alert messages; those are now
 * raised per tenant under a bound context and carry tenant_id, so the
 * operations dashboard can show an operator ONLY their own tenant's
 * data-quality alerts plus the global provider ones — never a competitor's
 * roster. Nullable (no backfill): existing rows are pre-tenancy global
 * telemetry and correctly stay global.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingestion_alerts', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });

        // AlertService's dedup fingerprint gained a tenant segment
        // (type|source → type|source|tenant). Existing rows were written by
        // the old 2-part scheme and are all global (tenant_id is NULL here —
        // this migration adds no per-tenant rows), so rewrite each to the new
        // 3-part scheme with the '-' tenant sentinel. Without this, on an
        // in-place upgrade a still-open provider alert would never match a
        // later resolve() (staying OPEN forever) and raise() would insert a
        // duplicate — the dedup/auto-resolve contract must survive the format
        // change. Recompute from the stored alert_type/source columns (the
        // fingerprint hash is not reversible).
        foreach (DB::table('ingestion_alerts')->get(['id', 'alert_type', 'source']) as $row) {
            DB::table('ingestion_alerts')->where('id', $row->id)->update([
                'fingerprint' => sha1($row->alert_type.'|'.($row->source ?? '-').'|-'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('ingestion_alerts', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
