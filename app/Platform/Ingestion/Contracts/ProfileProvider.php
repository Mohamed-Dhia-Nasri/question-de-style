<?php

namespace App\Platform\Ingestion\Contracts;

use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Shared\Enums\Platform;

/**
 * A provider adapter that fetches one account's public profile from its
 * frozen SRC-* source and returns normalized ProfileData
 * (docs/40-integrations/00-data-source-matrix.md §2.1 "Profile / channel
 * metadata + public counts"). Throws ProviderCallException on failure.
 */
interface ProfileProvider
{
    /** The exact SRC-* id this adapter calls (SourceRegistry constant). */
    public function source(): string;

    public function platform(): Platform;

    /** @return NormalizedBatch whose items are ProfileData (0 or 1) */
    public function fetchProfile(string $handle): NormalizedBatch;
}
