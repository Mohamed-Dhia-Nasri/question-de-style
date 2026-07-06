<?php

namespace App\Platform\Analytics\Contracts;

/**
 * SVC-Analytics (L5) — docs/60-architecture/00-system-architecture.md,
 * ADR-0010 as amended by ADR-0013.
 *
 * Maintains the dimensional star schema (FACT-* / DIM-* / ROLLUP-*, canonical
 * in docs/30-data-model/01-analytics-model.md) on Neon Postgres:
 *  - fact tables are APPEND-ONLY and tier-aware (every measure carries its
 *    ENUM-MetricTier; estimates never aggregate into a number that reads as
 *    fact — DP-001);
 *  - fact tables use native declarative partitioning by time (no TimescaleDB
 *    — Neon supports neither the extension nor pg_cron);
 *  - rollups are materialized views / rollup tables refreshed on a schedule
 *    by the app scheduler (`qds:refresh-rollups`), NOT by the database;
 *  - dashboards and SVC-Export read rollups only, never raw facts.
 *
 * Analytics DDL lives apart from OLTP migrations in
 * database/migrations/analytics/ (kept separate so the OLAP schema can move
 * to a columnar engine later without touching OLTP history — the ClickHouse
 * escape hatch in ADR-0010).
 *
 * Implementation (schema + rollup catalog) is remaining P0 work. Do not
 * create fact tables speculatively; only the canonical FACT- / DIM- / ROLLUP-
 * set may be migrated.
 */
interface AnalyticsService
{
    /**
     * Refresh every registered rollup. Invoked on schedule (e.g. every
     * 15–60 min per ADR-0013) by the `qds:refresh-rollups` command.
     *
     * @return int number of rollups refreshed
     */
    public function refreshRollups(): int;
}
