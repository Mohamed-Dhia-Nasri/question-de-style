<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant ownership for every tenant-owned business table (ADR-0019).
 *
 * Three things happen here, in order:
 *
 *  1. Every tenant-owned table gains a NOT NULL tenant_id → tenants FK.
 *     BACKFILL ASSUMPTION (explicit, not silent): rows that exist before
 *     this migration belong to the single pre-tenancy install — they are
 *     assigned to the earliest tenant (the founding tenant created by the
 *     users backfill). A table with rows but no tenant to own them aborts
 *     the migration loudly rather than guessing. The backfill uses ADD
 *     COLUMN ... DEFAULT <id> (PostgreSQL fills existing rows in the
 *     catalog, without UPDATEs), so the append-only row triggers on
 *     metric_snapshots / review_actions / emv_results never fire.
 *
 *  2. Natural-key unique constraints that are only unique WITHIN a tenant
 *     are re-keyed to include tenant_id: platform_accounts(platform,
 *     handle), content_items/stories(platform, external_id), the
 *     hashtag_lists entry index, and the EMV version + one-ACTIVE keys.
 *     Two tenants may now track the same public handle/post — duplicate
 *     ingestion cost across tenants is an accepted, documented consequence
 *     (ADR-0019). users.email intentionally stays globally unique.
 *
 *  3. Cross-tenant links become structurally impossible: every parent that
 *     other tenant-owned rows reference gains UNIQUE (id, tenant_id), and
 *     every FK between tenant-owned tables gains a composite
 *     (fk, tenant_id) → parent (id, tenant_id) constraint. The composite
 *     FKs use the default NO ACTION so they coexist with the existing
 *     cascade/null-on-delete single-column FKs (NO ACTION checks run at
 *     statement end, after cascades have removed child rows). MATCH
 *     SIMPLE semantics skip rows whose nullable FK column is NULL.
 *
 * audit_logs gets a NULLABLE tenant_id (system/platform actions carry
 * none), backfilled from the acting user. Operational registers
 * (provider_calls, provider_health_states, ingestion_alerts,
 * quarantined_records, provider_response_samples, ingestion_cycles,
 * analytics_watermarks, analytics_refreshes) stay GLOBAL platform
 * telemetry per the ADR-0019 ownership map.
 */
return new class extends Migration
{
    /** Tenant-owned tables receiving NOT NULL tenant_id (ADR-0019 ownership map). */
    private const TENANT_TABLES = [
        // CRM (M3)
        'clients', 'brands', 'products', 'creators', 'platform_accounts',
        'contacts', 'brand_preferences', 'campaigns', 'campaign_creator',
        'seeding_campaigns', 'seeding_campaign_creator', 'shipments',
        'shipment_resulting_content', 'communication_logs',
        'document_attachments', 'tasks',
        // Discovery (M2)
        'geo_attributions',
        // Monitoring / ingestion domain (M1)
        'monitored_subjects', 'content_items', 'stories', 'comments',
        'mentions', 'recognition_detections', 'sentiment_analyses',
        'metric_snapshots',
        // Enrichment (tenant-owned configuration + results)
        'hashtag_lists', 'content_hashtags', 'review_actions',
        'enrichment_runs', 'emv_configurations', 'emv_results',
        // Delivery / settings
        'export_jobs', 'monitoring_plan_settings',
    ];

    /** Parents that need UNIQUE (id, tenant_id) as the composite-FK target. */
    private const COMPOSITE_PARENTS = [
        'users', 'clients', 'brands', 'products', 'creators',
        'platform_accounts', 'campaigns', 'seeding_campaigns', 'shipments',
        'content_items', 'stories', 'monitored_subjects', 'comments',
        'hashtag_lists', 'emv_configurations',
    ];

    /** child table => [fk column => parent table] for composite tenant FKs. */
    private const COMPOSITE_FKS = [
        'brands' => ['client_id' => 'clients'],
        'products' => ['brand_id' => 'brands'],
        'campaigns' => ['brand_id' => 'brands'],
        'seeding_campaigns' => ['campaign_id' => 'campaigns', 'brand_id' => 'brands', 'product_id' => 'products'],
        'platform_accounts' => ['creator_id' => 'creators'],
        'contacts' => ['creator_id' => 'creators'],
        'brand_preferences' => ['creator_id' => 'creators'],
        'communication_logs' => ['creator_id' => 'creators', 'campaign_id' => 'campaigns'],
        'document_attachments' => ['creator_id' => 'creators', 'campaign_id' => 'campaigns', 'seeding_campaign_id' => 'seeding_campaigns'],
        'tasks' => ['creator_id' => 'creators', 'campaign_id' => 'campaigns', 'assignee_user_id' => 'users'],
        'geo_attributions' => ['creator_id' => 'creators'],
        'shipments' => ['seeding_campaign_id' => 'seeding_campaigns', 'creator_id' => 'creators', 'product_id' => 'products'],
        'campaign_creator' => ['campaign_id' => 'campaigns', 'creator_id' => 'creators'],
        'seeding_campaign_creator' => ['seeding_campaign_id' => 'seeding_campaigns', 'creator_id' => 'creators'],
        'shipment_resulting_content' => ['shipment_id' => 'shipments', 'content_item_id' => 'content_items'],
        'monitored_subjects' => ['creator_id' => 'creators', 'campaign_id' => 'campaigns'],
        'content_items' => ['platform_account_id' => 'platform_accounts'],
        'stories' => ['platform_account_id' => 'platform_accounts'],
        'comments' => ['content_item_id' => 'content_items', 'parent_comment_id' => 'comments'],
        'mentions' => ['monitored_subject_id' => 'monitored_subjects', 'content_item_id' => 'content_items', 'story_id' => 'stories', 'campaign_id' => 'campaigns'],
        'recognition_detections' => ['content_item_id' => 'content_items', 'story_id' => 'stories'],
        'sentiment_analyses' => ['content_item_id' => 'content_items', 'comment_id' => 'comments'],
        'metric_snapshots' => ['platform_account_id' => 'platform_accounts', 'content_item_id' => 'content_items'],
        'hashtag_lists' => ['campaign_id' => 'campaigns', 'brand_id' => 'brands', 'created_by' => 'users'],
        'content_hashtags' => ['content_item_id' => 'content_items', 'resolved_hashtag_list_id' => 'hashtag_lists', 'resolved_by' => 'users'],
        'enrichment_runs' => ['content_item_id' => 'content_items', 'story_id' => 'stories'],
        'emv_configurations' => ['created_by' => 'users', 'activated_by' => 'users'],
        'emv_results' => ['content_item_id' => 'content_items', 'emv_configuration_id' => 'emv_configurations'],
        'export_jobs' => ['user_id' => 'users'],
        'review_actions' => ['user_id' => 'users'],
        'monitoring_plan_settings' => ['updated_by' => 'users'],
    ];

    public function up(): void
    {
        $foundingTenantId = DB::table('tenants')->orderBy('id')->value('id');

        foreach (self::TENANT_TABLES as $table) {
            $this->addTenantColumn($table, $foundingTenantId === null ? null : (int) $foundingTenantId);
        }

        // audit_logs: nullable attribution — system rows legitimately have none.
        DB::statement('ALTER TABLE audit_logs ADD COLUMN tenant_id bigint');
        DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_tenant_id_foreign FOREIGN KEY (tenant_id) REFERENCES tenants (id)');
        DB::statement('CREATE INDEX audit_logs_tenant_id_index ON audit_logs (tenant_id)');
        DB::statement(<<<'SQL'
            UPDATE audit_logs
            SET tenant_id = users.tenant_id
            FROM users
            WHERE audit_logs.user_id = users.id AND audit_logs.tenant_id IS NULL
        SQL);

        $this->replaceNaturalKeyUniques();

        foreach (self::COMPOSITE_PARENTS as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_id_tenant_unique UNIQUE (id, tenant_id)");
        }

        foreach (self::COMPOSITE_FKS as $child => $links) {
            foreach ($links as $column => $parent) {
                DB::statement(
                    "ALTER TABLE {$child} ADD CONSTRAINT {$child}_{$column}_tenant_fk "
                    ."FOREIGN KEY ({$column}, tenant_id) REFERENCES {$parent} (id, tenant_id)"
                );
            }
        }

        // The owner must belong to the tenant they own: pairs
        // (owner_user_id → users.id, tenants.id → users.tenant_id).
        DB::statement(
            'ALTER TABLE tenants ADD CONSTRAINT tenants_owner_same_tenant_fk '
            .'FOREIGN KEY (owner_user_id, id) REFERENCES users (id, tenant_id)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_owner_same_tenant_fk');

        foreach (self::COMPOSITE_FKS as $child => $links) {
            foreach (array_keys($links) as $column) {
                DB::statement("ALTER TABLE {$child} DROP CONSTRAINT IF EXISTS {$child}_{$column}_tenant_fk");
            }
        }

        foreach (self::COMPOSITE_PARENTS as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_id_tenant_unique");
        }

        $this->restoreNaturalKeyUniques();

        DB::statement('ALTER TABLE audit_logs DROP COLUMN IF EXISTS tenant_id');

        foreach (self::TENANT_TABLES as $table) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS tenant_id");
        }
    }

    private function addTenantColumn(string $table, ?int $foundingTenantId): void
    {
        $hasRows = DB::table($table)->exists();

        if ($hasRows && $foundingTenantId === null) {
            throw new RuntimeException(
                "Table [{$table}] contains rows but no tenant exists to own them. "
                .'Refusing to guess ownership (ADR-0019): create the tenant and backfill explicitly.'
            );
        }

        if ($hasRows) {
            // Catalog-level default fill: no UPDATE statements, so the
            // append-only triggers never fire; the default is dropped
            // immediately — new rows must state their owner.
            DB::statement("ALTER TABLE {$table} ADD COLUMN tenant_id bigint NOT NULL DEFAULT {$foundingTenantId}");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN tenant_id DROP DEFAULT");
        } else {
            DB::statement("ALTER TABLE {$table} ADD COLUMN tenant_id bigint NOT NULL");
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_tenant_id_foreign FOREIGN KEY (tenant_id) REFERENCES tenants (id)");
        DB::statement("CREATE INDEX {$table}_tenant_id_index ON {$table} (tenant_id)");
    }

    private function replaceNaturalKeyUniques(): void
    {
        // (platform, handle) is only an external identity WITHIN a tenant.
        DB::statement('ALTER TABLE platform_accounts DROP CONSTRAINT platform_accounts_platform_handle_unique');
        DB::statement('ALTER TABLE platform_accounts ADD CONSTRAINT platform_accounts_tenant_platform_handle_unique UNIQUE (tenant_id, platform, handle)');

        // Ingestion idempotency keys become tenant-scoped.
        DB::statement('ALTER TABLE content_items DROP CONSTRAINT content_items_platform_external_id_unique');
        DB::statement('ALTER TABLE content_items ADD CONSTRAINT content_items_tenant_platform_external_id_unique UNIQUE (tenant_id, platform, external_id)');

        DB::statement('ALTER TABLE stories DROP CONSTRAINT stories_platform_external_id_unique');
        DB::statement('ALTER TABLE stories ADD CONSTRAINT stories_tenant_platform_external_id_unique UNIQUE (tenant_id, platform, external_id)');

        // Hashtag entries: the AGENCY scope degenerated to a global key —
        // tenant_id restores per-tenant registrability.
        DB::statement('DROP INDEX hashtag_lists_entry_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX hashtag_lists_entry_unique ON hashtag_lists (
                tenant_id,
                normalized,
                scope,
                COALESCE(campaign_id, 0),
                COALESCE(brand_id, 0),
                COALESCE(product_label, '')
            )
        SQL);

        // EMV: version namespace and the one-ACTIVE rule are per tenant.
        DB::statement('ALTER TABLE emv_configurations DROP CONSTRAINT emv_configurations_formula_version_rate_card_version_unique');
        DB::statement('ALTER TABLE emv_configurations ADD CONSTRAINT emv_configurations_tenant_versions_unique UNIQUE (tenant_id, formula_version, rate_card_version)');

        DB::statement('DROP INDEX emv_configurations_one_active_index');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX emv_configurations_one_active_index
            ON emv_configurations (tenant_id)
            WHERE status = 'ACTIVE'
        SQL);
    }

    private function restoreNaturalKeyUniques(): void
    {
        DB::statement('ALTER TABLE platform_accounts DROP CONSTRAINT IF EXISTS platform_accounts_tenant_platform_handle_unique');
        DB::statement('ALTER TABLE platform_accounts ADD CONSTRAINT platform_accounts_platform_handle_unique UNIQUE (platform, handle)');

        DB::statement('ALTER TABLE content_items DROP CONSTRAINT IF EXISTS content_items_tenant_platform_external_id_unique');
        DB::statement('ALTER TABLE content_items ADD CONSTRAINT content_items_platform_external_id_unique UNIQUE (platform, external_id)');

        DB::statement('ALTER TABLE stories DROP CONSTRAINT IF EXISTS stories_tenant_platform_external_id_unique');
        DB::statement('ALTER TABLE stories ADD CONSTRAINT stories_platform_external_id_unique UNIQUE (platform, external_id)');

        DB::statement('DROP INDEX IF EXISTS hashtag_lists_entry_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX hashtag_lists_entry_unique ON hashtag_lists (
                normalized,
                scope,
                COALESCE(campaign_id, 0),
                COALESCE(brand_id, 0),
                COALESCE(product_label, '')
            )
        SQL);

        DB::statement('ALTER TABLE emv_configurations DROP CONSTRAINT IF EXISTS emv_configurations_tenant_versions_unique');
        DB::statement('ALTER TABLE emv_configurations ADD CONSTRAINT emv_configurations_formula_version_rate_card_version_unique UNIQUE (formula_version, rate_card_version)');

        DB::statement('DROP INDEX IF EXISTS emv_configurations_one_active_index');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX emv_configurations_one_active_index
            ON emv_configurations ((1))
            WHERE status = 'ACTIVE'
        SQL);
    }
};
