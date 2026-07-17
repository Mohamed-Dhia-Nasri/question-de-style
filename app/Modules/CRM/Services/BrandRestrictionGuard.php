<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Exceptions\BrandRestrictionViolation;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Creator;
use App\Shared\Enums\RelationshipStatus;

/**
 * AC-M3-007: ENT-BrandPreference restrictions/blocklists act as HARD
 * filters when a creator joins a campaign or seeding run (module-3 §2.3).
 *
 * Restricted brands are canonical plain-string name lists; matching is a
 * case-insensitive exact comparison (Step-3 spec D4 — sector-level
 * restriction interpretation is not attempted in v1). A restriction matches
 * a brand's canonical name OR any of its aliases (item 5a); the throwing
 * path and the bulk path fold the SAME needle set so they cannot diverge.
 * The typed-name path (restrictedCreatorIdsForName) stays name-only because
 * the wizard's brand may not exist yet and carries no aliases.
 */
class BrandRestrictionGuard
{
    /** @throws BrandRestrictionViolation */
    public function assertNotRestricted(Creator $creator, Brand $brand): void
    {
        $needles = $this->needlesForBrand($brand);

        $violated = $creator->brandPreferences()
            ->get()
            ->contains(fn (BrandPreference $preference) => $this->restrictedByNeedles($preference->restricted_brands, $needles));

        if ($violated) {
            throw BrandRestrictionViolation::restricted($creator, $brand);
        }
    }

    /**
     * Bulk, NON-THROWING companion to assertNotRestricted for the roster
     * picker: which of these candidates does a restriction against $brand
     * skip? Folds the SAME needle set (name + aliases) as the throwing path.
     *
     * @param  list<int>  $creatorIds
     * @return list<int>
     */
    public function restrictedCreatorIds(array $creatorIds, Brand $brand): array
    {
        return $this->restrictedCreatorIdsForNeedles($creatorIds, $this->needlesForBrand($brand));
    }

    /**
     * Bulk companion for the "do not contact or book" status (item 5b): which
     * of these candidates carry RelationshipStatus::Blocklisted? Lives next to
     * the restriction matchers because it is the same join-time guard, applied
     * at every attach path. Tenant-scoped automatically (Creator uses
     * BelongsToTenant); relationship_status is nullable, so the equality filter
     * is null-safe (a NULL status simply never matches). Independent of any
     * brand — a blocklisted creator is off-limits everywhere.
     *
     * @param  list<int>  $creatorIds
     * @return list<int>
     */
    public function blocklistedCreatorIds(array $creatorIds): array
    {
        if ($creatorIds === []) {
            return [];
        }

        return Creator::query()
            ->whereIn('id', $creatorIds)
            ->where('relationship_status', RelationshipStatus::Blocklisted)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * True if a brand's needle set (name + aliases, folded) intersects an
     * already-folded name list. Exposed for read-only "does this brand
     * match" checks outside the throw/bulk paths (item 5c: re-checking a
     * creator's existing rosters when a restriction is newly added) —
     * folds the SAME way as assertNotRestricted/restrictedCreatorIds so
     * the three cannot diverge.
     *
     * @param  list<string>  $foldedNames  already mb_strtolower(trim())'d
     */
    public function matchesAnyNeedle(Brand $brand, array $foldedNames): bool
    {
        return collect($this->needlesForBrand($brand))->intersect($foldedNames)->isNotEmpty();
    }

    /**
     * The typed-name matcher for the wizard: the brand a manager types may
     * not exist yet, so it carries no aliases — match the name only.
     *
     * @param  list<int>  $creatorIds
     * @return list<int> unique creator ids, in first-seen order
     */
    public function restrictedCreatorIdsForName(array $creatorIds, string $brandName): array
    {
        return $this->restrictedCreatorIdsForNeedles($creatorIds, [mb_strtolower(trim($brandName))]);
    }

    /**
     * Folded needle set for a brand: its canonical name plus every alias,
     * lowercased and trimmed, de-duplicated, empties dropped. Case folding
     * stays in PHP — never SQL lower(), whose unicode rules diverge from
     * mb_strtolower.
     *
     * @return list<string>
     */
    private function needlesForBrand(Brand $brand): array
    {
        return collect([$brand->name, ...($brand->aliases ?? [])])
            ->map(fn ($name) => mb_strtolower(trim((string) $name)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * True if any entry of a restricted-brand list (folded the same way as
     * the needles) intersects the needle set.
     *
     * @param  list<string>|null  $restrictedBrands
     * @param  list<string>  $needles
     */
    private function restrictedByNeedles(?array $restrictedBrands, array $needles): bool
    {
        return collect($restrictedBrands ?? [])
            ->map(fn ($name) => mb_strtolower(trim((string) $name)))
            ->intersect($needles)
            ->isNotEmpty();
    }

    /**
     * One batched read of every candidate's preference rows; a creator is
     * restricted iff ANY entry of ANY of its rows folds into the needle set.
     * Shared by both the brand overload and the typed-name overload so the
     * matching logic lives in exactly one place.
     *
     * @param  list<int>  $creatorIds
     * @param  list<string>  $needles
     * @return list<int> unique creator ids, in first-seen order
     */
    private function restrictedCreatorIdsForNeedles(array $creatorIds, array $needles): array
    {
        if ($creatorIds === [] || $needles === []) {
            return [];
        }

        return BrandPreference::query()
            ->whereIn('creator_id', $creatorIds)
            ->get(['creator_id', 'restricted_brands'])
            ->filter(fn (BrandPreference $preference) => $this->restrictedByNeedles($preference->restricted_brands, $needles))
            ->pluck('creator_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
