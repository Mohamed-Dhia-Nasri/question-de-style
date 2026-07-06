<?php

namespace App\Platform\Ingestion\Support;

/**
 * Lifecycle of one monitoring cycle (AC-M1-001). Internal operational
 * vocabulary, not a canonical ENUM-*.
 */
enum CycleStatus: string
{
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    /** Finished, but at least one account/provider job failed. */
    case Partial = 'PARTIAL';
    case Failed = 'FAILED';
    /** Never finished and exceeded the staleness window; superseded. */
    case Stale = 'STALE';
}
