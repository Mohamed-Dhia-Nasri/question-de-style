<?php

namespace App\Platform\Ingestion\DTO;

use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;

/**
 * Normalized public content record (post / carousel / reel / video / short)
 * destined for ENT-ContentItem (REQ-M1-003). Never a story — stories are
 * StoryData → ENT-Story (rule F8). Always carries Provenance (DP-002) and
 * the platform's canonical external identifier for idempotent persistence.
 */
final readonly class ContentData
{
    public function __construct(
        public Platform $platform,
        /** Canonical platform-native content id — the dedup key. */
        public string $externalId,
        public ContentType $contentType,
        public ?string $caption,
        /** @var list<string> public media references */
        public array $mediaUrls,
        public ?CarbonImmutable $publishedAt,
        /** @var list<MetricValue> public counts, each tier PUBLIC (DP-001) */
        public array $publicMetrics,
        public Provenance $provenance,
        /**
         * Canonical public page URL (post/reel/video page, never a CDN
         * media URL). Feeds the campaign-linked direct-URL metric refresh;
         * null when the provider payload does not carry it.
         */
        public ?string $permalink = null,
    ) {}
}
