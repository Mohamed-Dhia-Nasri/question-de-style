<?php

namespace App\Platform\Snapshots\Contracts;

/**
 * SVC-SnapshotScheduler (L3) — docs/60-architecture/00-system-architecture.md,
 * ADR-0003 (historical growth via own-DB snapshots).
 *
 * Writes recurring, timestamped ENT-MetricSnapshot records — the SOLE
 * producer of historical growth data; there is no external history API.
 * Each snapshot carries Provenance naming the PUBLIC source that supplied the
 * point-in-time counts.
 *
 * Implementation is remaining P0 work (depends on the ingestion connectors
 * and the ENT-MetricSnapshot migration).
 */
interface SnapshotScheduler
{
    /**
     * Capture snapshots for every account/content item due in this run.
     * Invoked by the `qds:capture-snapshots` scheduled command.
     *
     * @return int number of snapshots captured
     */
    public function captureDueSnapshots(): int;
}
