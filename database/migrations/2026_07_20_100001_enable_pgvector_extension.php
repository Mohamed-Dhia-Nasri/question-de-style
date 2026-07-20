<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * pgvector (ADR-0029, sub-project C): vector similarity for visual
     * product matching. CREATE EXTENSION is per DATABASE, not per cluster,
     * so this one migration covers local dev (qds), the test database
     * (qds_test) and Neon alike; it installs into `public` — the hard-coded
     * search_path (config/database.php). On Neon no special role is needed.
     * Local docker must run pgvector/pgvector:pg17-bookworm (drop-in
     * postgres:17 replacement — see docker-compose.yml and README#testing).
     * Neon ships pgvector 0.8.0 on PG17: all vector code pins itself to
     * 0.8.0 semantics (exact scan, `<=>` cosine distance; no post-0.8.0
     * features).
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        // No CASCADE on purpose: if any vector column still exists (a later
        // migration not yet rolled back), this fails loudly instead of
        // silently dropping embedding data.
        DB::statement('DROP EXTENSION IF EXISTS vector');
    }
};
