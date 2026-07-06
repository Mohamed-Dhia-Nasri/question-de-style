<?php

namespace App\Modules\CRM\DTO;

use App\Shared\Enums\Platform;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;

/**
 * XMC-001 proposal payload: a creator observed by M1/M2 that is not yet in
 * the CRM (module-3 §5; module-2 §5 sequence — "candidate + PlatformAccount
 * refs + Provenance"). Field shapes trace to ENT-Creator (displayName) and
 * ENT-PlatformAccount (platform, handle, bio, externalLinks, followerCount);
 * proposals originate from external observation, so Provenance is mandatory
 * (DP-002). No contact data ever rides along (DEF-002).
 */
final readonly class CreatorProposal
{
    public function __construct(
        /** ENT-Creator.displayName for the proposed creator. */
        public string $displayName,
        /** The observed platform account the proposal anchors to. */
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
