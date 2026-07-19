<?php

namespace App\Platform\Enrichment\TextSignals;

final readonly class ResolvedProduct
{
    public function __construct(
        public int $productId,
        public string $name,
        public int $brandId,
        public string $brandName,
        public string $rung, // 'sku' | 'name' | 'alias' — which ladder rung matched
    ) {}
}
