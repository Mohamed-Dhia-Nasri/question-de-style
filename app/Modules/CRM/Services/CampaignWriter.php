<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Exceptions\CampaignBrandLocked;
use App\Modules\CRM\Models\Campaign;
use App\Shared\Audit\AuditLogger;

/**
 * The single sanctioned write path for ENT-Campaign. Every surface that
 * creates or edits a campaign — the campaigns index, the guided wizard, any
 * future path — routes through here, so the brand-coherence invariant is
 * enforced in ONE place instead of leaking across callers.
 *
 * F14: a seeding run denormalizes its campaign's brand_id, and that coherence
 * rule historically lived only on the seeding write path. Editing the
 * campaign's brand out from under live runs therefore silently desynced them.
 * updateCampaign houses the guard (block-and-tell — never a silent cascade
 * that rewrites child brand_ids).
 *
 * Neither method opens a transaction of its own, so createCampaign can be
 * called from inside the wizard's single commit transaction.
 */
final class CampaignWriter
{
    /**
     * Create a campaign and record the creation. A brand-new campaign is
     * coherent by construction (it has no runs yet), so no guard applies.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createCampaign(array $attributes, AuditLogger $audit): Campaign
    {
        $campaign = Campaign::create($attributes);

        $audit->record('campaign.created', $campaign, ['name' => $campaign->name]);

        return $campaign;
    }

    /**
     * Edit a campaign's own fields. Changing the brand is refused while any
     * seeding run still hangs off the campaign (F14). The status transition
     * audit event (AC-M3-009) fires here, mirroring the shape the index used
     * before this became the single write path.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws CampaignBrandLocked
     */
    public function updateCampaign(Campaign $campaign, array $attributes, AuditLogger $audit): Campaign
    {
        $this->assertBrandChangeAllowed($campaign, $attributes);

        $previousStatus = $campaign->status;

        $campaign->update($attributes);

        $audit->record('campaign.updated', $campaign, ['name' => $campaign->name]);

        // AC-M3-009: lifecycle transitions are recorded (from → to).
        if ($previousStatus !== $campaign->status) {
            $audit->record('campaign.status_changed', $campaign, [
                'from' => $previousStatus->value,
                'to' => $campaign->status->value,
            ]);
        }

        return $campaign;
    }

    /**
     * Block a brand change while runs still denormalize the current brand.
     * Only a genuine change to a different brand is guarded; a no-op write of
     * the same brand_id passes, so unrelated edits on a campaign with runs are
     * unaffected.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws CampaignBrandLocked
     */
    private function assertBrandChangeAllowed(Campaign $campaign, array $attributes): void
    {
        if (
            isset($attributes['brand_id'])
            && (int) $attributes['brand_id'] !== $campaign->brand_id
            && ($count = $campaign->seedingCampaigns()->count()) > 0
        ) {
            throw CampaignBrandLocked::forCampaign($campaign, $count);
        }
    }
}
