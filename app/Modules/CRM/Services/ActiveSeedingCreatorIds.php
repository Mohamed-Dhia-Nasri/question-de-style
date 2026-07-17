<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Enums\SeedingCampaignStatus;

/**
 * Creators enrolled in a currently running seeding — the single owner of
 * the "active seeding" definition (ACTIVE + SHIPPING) shared by the home
 * dashboard tile and the monitoring "Active seeding only" filter.
 * Tenant-scoped via SeedingCampaign's BelongsToTenant (ADR-0019).
 */
class ActiveSeedingCreatorIds
{
    /** @var list<SeedingCampaignStatus> */
    public const ACTIVE_STATUSES = [
        SeedingCampaignStatus::Active,
        SeedingCampaignStatus::Shipping,
    ];

    /** @return list<string> */
    public static function statusValues(): array
    {
        return array_map(
            fn (SeedingCampaignStatus $status): string => $status->value,
            self::ACTIVE_STATUSES,
        );
    }

    /**
     * Distinct IDs of creators on the roster of an ACTIVE/SHIPPING seeding
     * run in the current tenant. Empty array when none — callers must gate
     * their filters on an explicit flag, never on this array's truthiness.
     *
     * @return list<int>
     */
    public function forCurrentTenant(): array
    {
        return SeedingCampaign::query()
            ->whereIn('status', self::statusValues())
            ->join(
                'seeding_campaign_creator',
                'seeding_campaign_creator.seeding_campaign_id',
                '=',
                'seeding_campaigns.id',
            )
            ->pluck('seeding_campaign_creator.creator_id')
            ->unique()
            ->values()
            ->all();
    }
}
