<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Exceptions\BrandRestrictionViolation;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Creator;

/**
 * AC-M3-007: ENT-BrandPreference restrictions/blocklists act as HARD
 * filters when a creator joins a campaign or seeding run (module-3 §2.3).
 *
 * Restricted brands are canonical plain-string name lists; matching is a
 * case-insensitive exact name comparison (Step-3 spec D4 — sector-level
 * restriction interpretation is not attempted in v1).
 */
class BrandRestrictionGuard
{
    /** @throws BrandRestrictionViolation */
    public function assertNotRestricted(Creator $creator, Brand $brand): void
    {
        $needle = mb_strtolower(trim($brand->name));

        $restricted = $creator->brandPreferences()
            ->get()
            ->flatMap(fn ($preference) => $preference->restricted_brands ?? [])
            ->map(fn (string $name) => mb_strtolower(trim($name)));

        if ($restricted->contains($needle)) {
            throw BrandRestrictionViolation::restricted($creator, $brand);
        }
    }
}
