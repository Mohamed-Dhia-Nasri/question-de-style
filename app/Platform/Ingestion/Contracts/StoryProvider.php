<?php

namespace App\Platform\Ingestion\Contracts;

use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Shared\Enums\Platform;

/**
 * A provider adapter that fetches an account's currently-live stories from
 * its frozen SRC-* source before platform expiry (REQ-M1-004, AC-M1-005)
 * and returns normalized StoryData. Instagram-only in v1
 * (docs/40-integrations/00-data-source-matrix.md §2.1).
 * Throws ProviderCallException on failure.
 */
interface StoryProvider
{
    /** The exact SRC-* id this adapter calls (SourceRegistry constant). */
    public function source(): string;

    public function platform(): Platform;

    /** @return NormalizedBatch whose items are StoryData */
    public function fetchStories(string $handle): NormalizedBatch;
}
