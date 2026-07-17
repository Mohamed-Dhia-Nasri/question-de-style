<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Exceptions\BrandRestrictionViolation;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\BrandPreference;
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

    /**
     * Bulk, NON-THROWING companion to assertNotRestricted for the roster
     * picker: which of these candidates does a restriction against $brand
     * skip? Delegates on the brand's name so a single matching rule serves
     * both overloads.
     *
     * @param  list<int>  $creatorIds
     * @return list<int>
     */
    public function restrictedCreatorIds(array $creatorIds, Brand $brand): array
    {
        return $this->restrictedCreatorIdsForName($creatorIds, $brand->name);
    }

    /**
     * The matching logic, byte-identical to assertNotRestricted: one batched
     * read of every candidate's preference rows, needle case-folded and
     * trimmed, a creator restricted iff ANY entry of ANY of its rows folds to
     * the needle. Case folding stays in PHP — never SQL lower(), whose
     * unicode rules diverge from mb_strtolower.
     *
     * @param  list<int>  $creatorIds
     * @return list<int> unique creator ids, in first-seen order
     */
    public function restrictedCreatorIdsForName(array $creatorIds, string $brandName): array
    {
        if ($creatorIds === []) {
            return [];
        }

        $needle = mb_strtolower(trim($brandName));

        return BrandPreference::query()
            ->whereIn('creator_id', $creatorIds)
            ->get(['creator_id', 'restricted_brands'])
            ->filter(fn (BrandPreference $preference) => collect($preference->restricted_brands ?? [])
                ->map(fn (string $name) => mb_strtolower(trim($name)))
                ->contains($needle))
            ->pluck('creator_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
