<?php

namespace App\Platform\Ingestion\Contracts;

use App\Shared\Enums\Platform;

/**
 * SVC-Ingestion (L2) — docs/60-architecture/00-system-architecture.md.
 *
 * Calls the frozen SRC-* sources (SourceRegistry), writes raw records, and
 * attaches the Provenance envelope to every externally-sourced record
 * (DP-002). Performs NO inference — that is SVC-EnrichmentAI's job.
 */
interface IngestionService
{
    /**
     * Fetch the public profile + recent public content (+ stories where the
     * platform supports them) for one ROSTER platform account and persist
     * raw records, each carrying Provenance (REQ-M1-001). Runs inline;
     * the recurring path is startMonitoringCycle(). Never creates accounts
     * — ENT-PlatformAccount is CRM-owned (ownership matrix).
     */
    public function ingestPlatformAccount(Platform $platform, string $handle): void;

    /**
     * Start one queued monitoring cycle over the whole active roster
     * (AC-M1-001). Duplicate cycles are prevented (unique job + fresh
     * RUNNING-cycle guard). Story-only cycles run on a tighter cadence
     * because stories expire (REQ-M1-004).
     */
    public function startMonitoringCycle(bool $storiesOnly = false): void;

    /**
     * Start one queued on-demand cycle over a single creator's platform
     * accounts (operator "run monitoring now"). Same fan-out and cycle
     * bookkeeping as the roster cycle, scoped by ingestion_cycles.creator_id;
     * duplicate runs for the same creator are prevented. The recurring
     * roster cycle remains the steady-state path.
     */
    public function startCreatorCycle(int $creatorId): void;
}
