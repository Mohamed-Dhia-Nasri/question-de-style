<?php

namespace App\Platform\Ingestion\DTO;

use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;
use Carbon\CarbonImmutable;

/**
 * Normalized ephemeral story destined for ENT-Story archival before platform
 * expiry (REQ-M1-004, AC-M1-005). Always carries Provenance (DP-002).
 *
 * `mediaSourceUrl` is the provider's public CDN reference used ONCE to
 * download the media into private object storage; the archived storage path
 * — not this URL — becomes the story's `media_url`.
 */
final readonly class StoryData
{
    public function __construct(
        public Platform $platform,
        /** Canonical platform-native story id — the dedup key. */
        public string $externalId,
        public ?string $mediaSourceUrl,
        public ?CarbonImmutable $expiresAt,
        /** @var list<MetricValue> public story metrics, tier PUBLIC */
        public array $publicMetrics,
        public Provenance $provenance,
    ) {}
}
