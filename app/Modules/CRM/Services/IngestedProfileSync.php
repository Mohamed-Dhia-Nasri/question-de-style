<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Ingestion\Contracts\PlatformAccountProfileSync;
use App\Platform\Ingestion\DTO\ProfileData;
use App\Platform\Ingestion\Persistence\PersistenceResult;

/**
 * Module 3 CRM's implementation of the ingestion→CRM profile-sync contract
 * (XMC-*). CRM is ENT-PlatformAccount's sole write-owner (ownership
 * matrix); this service is the ONE write path through which polled public
 * profile data lands on the account row.
 *
 * Only externally-sourced PUBLIC fields are touched: bio, external links,
 * follower count (MetricValue tier PUBLIC), and the fresh Provenance.
 * Identity fields (creator_id, platform, handle) and manual CRM data are
 * never written here; contact auto-extraction stays out entirely (DEF-002).
 */
class IngestedProfileSync implements PlatformAccountProfileSync
{
    public function apply(PlatformAccount $account, ProfileData $profile): PersistenceResult
    {
        $startedAt = microtime(true);

        if ($profile->platform !== $account->platform) {
            return new PersistenceResult(skipped: 1, persistenceMs: (microtime(true) - $startedAt) * 1000);
        }

        $account->update([
            'bio' => $profile->bio ?? $account->bio,
            'external_links' => $profile->externalLinks !== [] ? $profile->externalLinks : $account->external_links,
            'follower_count' => $profile->followerCount ?? $account->follower_count,
            'provenance' => $profile->provenance,
        ]);

        return new PersistenceResult(
            duplicates: 1,
            persistenceMs: (microtime(true) - $startedAt) * 1000,
        );
    }
}
