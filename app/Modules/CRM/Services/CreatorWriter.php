<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\ValueObjects\MetricValue;
use App\Shared\ValueObjects\Provenance;

/**
 * SVC-CRM's single sanctioned write path for ENT-Creator and
 * ENT-PlatformAccount (ownership matrix: "ALL Creator writes route through
 * the CRM/ingestion service"). Application code — seeders, consoles,
 * later CRM UI — creates creators and attaches platform accounts HERE,
 * never via direct model writes from non-owner modules.
 *
 * Step-1 seam: thin, explicit creation methods. Identity MERGE (and its
 * audit trail + review queue) is M3 Step-2 scope; cross-module proposals
 * enter through the XMC-001 contract (CreatorProposals), not here.
 */
class CreatorWriter
{
    public function createCreator(
        string $displayName,
        ?string $primaryLanguage = null,
        ?RelationshipStatus $relationshipStatus = null,
    ): Creator {
        return Creator::create([
            'display_name' => $displayName,
            'primary_language' => $primaryLanguage,
            'relationship_status' => $relationshipStatus,
        ]);
    }

    /**
     * Attach a platform account to a creator. Provenance is mandatory —
     * ENT-PlatformAccount is externally sourced (DP-002).
     *
     * @param  list<string>  $externalLinks
     */
    public function addPlatformAccount(
        Creator $creator,
        Platform $platform,
        string $handle,
        Provenance $provenance,
        ?string $bio = null,
        array $externalLinks = [],
        ?MetricValue $followerCount = null,
    ): PlatformAccount {
        return $creator->platformAccounts()->create([
            'platform' => $platform,
            'handle' => $handle,
            'bio' => $bio,
            'external_links' => $externalLinks,
            'follower_count' => $followerCount,
            'provenance' => $provenance,
        ]);
    }
}
