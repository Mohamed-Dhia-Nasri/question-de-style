<?php

namespace App\Platform\Ingestion\DTO;

use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;

/**
 * Normalized public-profile payload for one platform account
 * (raw → domain mapping, docs/40-integrations/00-data-source-matrix.md §4).
 * Populates the externally-sourced PUBLIC fields of ENT-PlatformAccount and
 * feeds account-level ENT-MetricSnapshot capture. Always carries Provenance
 * (DP-002).
 */
final readonly class ProfileData
{
    public function __construct(
        public Platform $platform,
        public string $handle,
        public ?string $bio,
        /** @var list<string> public links from the profile (never email/phone — DEF-002) */
        public array $externalLinks,
        /** Public follower/subscriber count, tier PUBLIC (DP-001). */
        public ?MetricValue $followerCount,
        public Provenance $provenance,
    ) {}
}
