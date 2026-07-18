<?php

namespace App\Platform\Ingestion\DTO;

/**
 * A product the platform itself tagged on a post (Instagram shopping tag,
 * etc.). Fields are whatever the provider supplied — any may be null; the
 * ProductResolver maps them onto a CRM product (never fabricated).
 */
final readonly class ProductTag
{
    public function __construct(
        public ?string $brandRef,       // brand name/handle as the provider gave it
        public ?string $productName,
        public ?string $productSku,
        public ?string $providerTagId,  // the platform's own tag id, when present
    ) {}
}
