<?php

namespace App\Platform\Enrichment\Support;

/**
 * Lifecycle of an EMV configuration (REQ-M1-011). Internal operational
 * vocabulary — NOT a canonical ENUM-*. EMV stays unavailable until an
 * authorized user activates a valid configuration; historical versions are
 * never deleted (INACTIVE/ARCHIVED) so past results stay reproducible.
 */
enum EmvConfigurationStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Archived = 'ARCHIVED';
}
