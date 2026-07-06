<?php

namespace App\Platform\Enrichment;

use App\Platform\Enrichment\Contracts\EnrichmentService;
use App\Shared\Exceptions\NotYetImplemented;

class PendingEnrichmentService implements EnrichmentService
{
    public function enrich(object $record): void
    {
        throw NotYetImplemented::service('SVC-EnrichmentAI', 'P1');
    }
}
