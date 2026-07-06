<?php

namespace App\Platform\Ingestion\Contracts;

use App\Platform\Ingestion\DTO\NormalizedBatch;
use App\Shared\Enums\Platform;

/**
 * A provider adapter that fetches an account's recent public content
 * (posts / carousels / reels / videos / shorts — never stories) from its
 * frozen SRC-* source and returns normalized ContentData (REQ-M1-003).
 * Throws ProviderCallException on failure.
 */
interface ContentProvider
{
    /** The exact SRC-* id this adapter calls (SourceRegistry constant). */
    public function source(): string;

    public function platform(): Platform;

    /** @return NormalizedBatch whose items are ContentData */
    public function fetchContent(string $handle): NormalizedBatch;
}
