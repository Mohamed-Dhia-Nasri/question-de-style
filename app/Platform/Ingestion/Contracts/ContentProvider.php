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

    /**
     * Fetch recent content. Adapters apply the qds.ingestion refresh-window
     * date filter provider-side (cost plan rec 1) unless $fullDepth is set —
     * the periodic sweep that catches late-blooming engagement on posts
     * older than the window.
     *
     * @return NormalizedBatch whose items are ContentData
     */
    public function fetchContent(string $handle, bool $fullDepth = false): NormalizedBatch;
}
