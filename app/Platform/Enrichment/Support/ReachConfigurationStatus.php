<?php

namespace App\Platform\Enrichment\Support;

/**
 * Lifecycle of a reach-estimation configuration (REQ-M1-006,
 * MET-EstimatedReach). Internal operational vocabulary — NOT a canonical
 * ENUM-*. Estimated reach stays unavailable until an authorized user
 * activates a valid configuration; historical versions are never deleted
 * (INACTIVE/ARCHIVED) so past results stay reproducible.
 */
enum ReachConfigurationStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Archived = 'ARCHIVED';
}
