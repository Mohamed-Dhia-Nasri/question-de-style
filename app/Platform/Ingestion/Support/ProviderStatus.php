<?php

namespace App\Platform\Ingestion\Support;

/**
 * Current health state of one SRC-* provider, maintained after every call
 * (P4 data-quality groundwork, roadmap delivery risk "scraper fragility").
 * Internal operational vocabulary, not a canonical ENUM-*.
 */
enum ProviderStatus: string
{
    case Healthy = 'HEALTHY';
    case Degraded = 'DEGRADED';
    case Failing = 'FAILING';
    case Unknown = 'UNKNOWN';
}
