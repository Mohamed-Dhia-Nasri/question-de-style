<?php

namespace App\Platform\Ingestion\Support;

/**
 * Normalized error categories for external provider calls (SVC-Ingestion
 * observability). Internal operational vocabulary — NOT a canonical
 * ENUM-* from docs/00-meta/03-glossary.md; never surfaces in domain data.
 */
enum ErrorCategory: string
{
    case Authentication = 'AUTHENTICATION';
    case Timeout = 'TIMEOUT';
    case RateLimited = 'RATE_LIMITED';
    case Network = 'NETWORK';
    case UpstreamError = 'UPSTREAM_ERROR';
    case MalformedResponse = 'MALFORMED_RESPONSE';
    case SchemaDrift = 'SCHEMA_DRIFT';
    case MissingRequiredFields = 'MISSING_REQUIRED_FIELDS';
    case InvalidFieldTypes = 'INVALID_FIELD_TYPES';
    case EmptyUnexpected = 'EMPTY_UNEXPECTED';
    case NormalizationFailed = 'NORMALIZATION_FAILED';
    case PersistenceFailed = 'PERSISTENCE_FAILED';
    case Unknown = 'UNKNOWN';

    /** Transient categories are worth retrying; the rest fail fast. */
    public function isTransient(): bool
    {
        return in_array($this, [
            self::Timeout,
            self::RateLimited,
            self::Network,
            self::UpstreamError,
        ], true);
    }
}
