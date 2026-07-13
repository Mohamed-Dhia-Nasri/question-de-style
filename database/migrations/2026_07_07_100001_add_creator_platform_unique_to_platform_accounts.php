<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Deep-review finding L1 — DB backstop for the one-account-per-platform-
     * per-creator invariant (docs/30-data-model/00-data-model.md#ent-creator:
     * "one per ENUM-Platform presence").
     *
     * CreatorWriter::assertPlatformFree() is a lock-free SELECT-then-INSERT:
     * two overlapping operator requests adding the same platform with
     * DIFFERENT handles both pass the app check (and the (platform, handle)
     * unique key cannot catch them), silently leaving one Creator with two
     * same-platform accounts. This partial unique index turns that race into
     * a QueryException, which CreatorWriter translates back into the
     * existing PlatformAccountConflict UI error path. Partial because
     * creator_id is nullable by schema; unassigned accounts are exempt.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX platform_accounts_creator_platform_unique
                ON platform_accounts (creator_id, platform)
                WHERE creator_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS platform_accounts_creator_platform_unique');
    }
};
