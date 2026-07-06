<?php

namespace App\Platform\Export\Support;

/**
 * Operational lifecycle of an export job. Internal vocabulary — not a
 * canonical ENUM-* (the glossary set is closed; this is service state).
 */
enum ExportJobStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Expired = 'EXPIRED';
}
