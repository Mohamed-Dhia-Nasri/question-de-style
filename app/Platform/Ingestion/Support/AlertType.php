<?php

namespace App\Platform\Ingestion\Support;

/**
 * Deduplicated ingestion alert types. Internal operational vocabulary, not
 * a canonical ENUM-*.
 */
enum AlertType: string
{
    case RepeatedFailures = 'REPEATED_FAILURES';
    case SchemaDrift = 'SCHEMA_DRIFT';
    case StaleData = 'STALE_DATA';
    case AbnormalDuration = 'ABNORMAL_DURATION';
    case ExcessiveRetries = 'EXCESSIVE_RETRIES';
    /** Story polling has not succeeded within the expiry-safe window (REQ-M1-004). */
    case StoryPollingRisk = 'STORY_POLLING_RISK';
    case JobFailed = 'JOB_FAILED';
    /** A provider is answering with rate-limit responses — budget/quota at risk. */
    case RateLimitRisk = 'RATE_LIMIT_RISK';
}
