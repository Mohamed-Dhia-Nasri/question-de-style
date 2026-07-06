<?php

namespace App\Platform\Enrichment\Support;

/**
 * Status of one enrichment pass over a ContentItem or Story. Internal
 * operational vocabulary — NOT a canonical ENUM-*. PARTIAL means at least
 * one stage failed or was skipped while others completed.
 */
enum EnrichmentRunStatus: string
{
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    case Partial = 'PARTIAL';
    case Failed = 'FAILED';
}
