<?php

namespace App\Platform\Ingestion\Contracts;

use App\Platform\Ingestion\DTO\ProfileData;

/**
 * A ContentProvider whose content payload already carries the account's
 * public profile data, so no separate profile call is needed (cost plan
 * rec 4: the TikTok actor's every video item embeds authorMeta — a
 * dedicated profile run re-bills a result and a per-run start fee for data
 * the content run returned anyway).
 *
 * Contract: profileFromLastFetch() exposes the profile observed during the
 * most recent fetchContent() on THIS adapter instance, or null when none
 * was seen. Callers apply it through the same CRM-owned
 * PlatformAccountProfileSync used by IngestProfileJob.
 */
interface ProvidesProfileFromContent extends ContentProvider
{
    public function profileFromLastFetch(): ?ProfileData;
}
