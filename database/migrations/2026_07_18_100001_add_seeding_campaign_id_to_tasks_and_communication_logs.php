<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage D schema foundation: tasks and communication_logs gain a nullable
 * `seeding_campaign_id` anchor, mirroring the existing document_attachments
 * seeding anchor (module-3 §2.9-style, any-combination attach). Unlike that
 * earlier migration, tenant ownership (ADR-0019) now exists, so this one
 * also adds the composite tenant FK — seeding_campaigns already carries
 * UNIQUE(id, tenant_id) from the tenant-ownership migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('seeding_campaign_id')->nullable()->index()->constrained();
        });
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_seeding_campaign_id_tenant_fk FOREIGN KEY (seeding_campaign_id, tenant_id) REFERENCES seeding_campaigns (id, tenant_id)');

        Schema::table('communication_logs', function (Blueprint $table) {
            $table->foreignId('seeding_campaign_id')->nullable()->index()->constrained();
        });
        DB::statement('ALTER TABLE communication_logs ADD CONSTRAINT communication_logs_seeding_campaign_id_tenant_fk FOREIGN KEY (seeding_campaign_id, tenant_id) REFERENCES seeding_campaigns (id, tenant_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_seeding_campaign_id_tenant_fk');
        Schema::table('tasks', fn (Blueprint $t) => $t->dropConstrainedForeignId('seeding_campaign_id'));
        DB::statement('ALTER TABLE communication_logs DROP CONSTRAINT IF EXISTS communication_logs_seeding_campaign_id_tenant_fk');
        Schema::table('communication_logs', fn (Blueprint $t) => $t->dropConstrainedForeignId('seeding_campaign_id'));
    }
};
