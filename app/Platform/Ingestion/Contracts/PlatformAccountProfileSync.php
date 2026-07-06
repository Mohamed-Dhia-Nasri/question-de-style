<?php

namespace App\Platform\Ingestion\Contracts;

use App\Modules\CRM\Models\PlatformAccount;
use App\Platform\Ingestion\DTO\ProfileData;
use App\Platform\Ingestion\Persistence\PersistenceResult;

/**
 * Cross-module write contract (XMC-*, ownership matrix rule 3):
 * ENT-PlatformAccount is write-owned by Module 3 CRM, so SVC-Ingestion
 * never updates it directly. Ingested public-profile data is handed to
 * this CRM-implemented contract, which applies ONLY the externally-sourced
 * PUBLIC profile fields (bio, links, follower count, provenance) — never
 * identity fields (creator_id, handle) and never contact data (DEF-002).
 */
interface PlatformAccountProfileSync
{
    public function apply(PlatformAccount $account, ProfileData $profile): PersistenceResult;
}
