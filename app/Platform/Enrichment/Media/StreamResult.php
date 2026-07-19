<?php

namespace App\Platform\Enrichment\Media;

final readonly class StreamResult
{
    public function __construct(
        public StreamStatus $status,
        public ?string $contentType = null,
    ) {}
}
