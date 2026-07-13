<?php

namespace App\Modules\CRM\Exceptions;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Creator;
use RuntimeException;

/**
 * A creator with an ENT-BrandPreference restriction/blocklist entry for a
 * brand was about to join that brand's campaign or seeding run. Restrictions
 * are enforced as HARD FILTERS on join (module-3 §2.3, AC-M3-007) — the UI
 * surfaces this as a caught validation error, never a silent attach.
 */
class BrandRestrictionViolation extends RuntimeException
{
    public static function restricted(Creator $creator, Brand $brand): self
    {
        return new self(
            "Creator [{$creator->display_name}] has a brand restriction against [{$brand->name}] "
            .'and cannot be added to this campaign or seeding run.'
        );
    }
}
