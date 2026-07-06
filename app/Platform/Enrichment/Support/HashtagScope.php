<?php

namespace App\Platform\Enrichment\Support;

/**
 * Scope of a configured hashtag list entry (campaign / brand / product /
 * agency). Internal operational vocabulary — NOT a canonical ENUM-*; the
 * data model defines no hashtag entity (flagged deviation, see the
 * hashtag_lists migration).
 */
enum HashtagScope: string
{
    case Campaign = 'CAMPAIGN';
    case Brand = 'BRAND';
    case Product = 'PRODUCT';
    case Agency = 'AGENCY';
}
