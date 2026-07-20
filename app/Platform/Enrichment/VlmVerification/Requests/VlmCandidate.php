<?php

namespace App\Platform\Enrichment\VlmVerification\Requests;

/**
 * One catalog candidate of the CLOSED answer set (spec §6): the stable
 * key P<product_id> the responseSchema enum-grounds on, the denormalized
 * label C matched, live brand/alias context, and C's similarity band/score
 * as prompt context. The VLM can only ever answer in terms of these keys —
 * out-of-catalog products are impossible at the decoding level.
 */
final readonly class VlmCandidate
{
    public function __construct(
        public string $key,
        public int $productId,
        public string $label,
        public string $brand,
        public ?string $category,
        /** @var list<string> */
        public array $aliases,
        public ?string $cBand,
        public ?float $cScore,
    ) {}
}
