<?php

namespace App\Platform\Ingestion\Support;

/**
 * Outcome of one external provider call after validation, normalization,
 * and persistence. PARTIAL means the call returned but some records were
 * rejected/quarantined. Internal operational vocabulary, not a canonical
 * ENUM-*.
 */
enum CallOutcome: string
{
    case Success = 'SUCCESS';
    case Partial = 'PARTIAL';
    case Failure = 'FAILURE';
}
