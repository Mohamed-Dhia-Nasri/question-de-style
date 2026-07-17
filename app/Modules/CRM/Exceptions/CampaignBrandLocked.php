<?php

namespace App\Modules\CRM\Exceptions;

use App\Modules\CRM\Models\Campaign;
use RuntimeException;

/**
 * A campaign's brand was about to change while seeding runs still hang off it.
 * Each run denormalizes the campaign's brand_id, and the coherence rule lives
 * on the write path (F14) — so changing the campaign's brand out from under
 * live runs would silently desync them. Enforced as block-and-tell: the
 * operator moves or re-brands the runs first, we never cascade a rewrite. The
 * UI surfaces this as a caught validation error.
 */
class CampaignBrandLocked extends RuntimeException
{
    public static function forCampaign(Campaign $campaign, int $runCount): self
    {
        return new self(
            "This campaign has {$runCount} seeding run(s) under its current brand. "
            .'Change their brand or move them before changing the campaign’s brand.'
        );
    }
}
