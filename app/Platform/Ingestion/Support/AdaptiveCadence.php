<?php

namespace App\Platform\Ingestion\Support;

use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Ingestion\Models\ProviderCall;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\SeedingCampaignStatus;
use Carbon\CarbonImmutable;

/**
 * Tiered, adaptive poll cadence (cost plan rec 7 + product-owner plan
 * decision 2026-07-08): the operator-chosen plan (CadenceSettings) sets
 * how often each TIER of creator is content-polled —
 * - campaign tier: creators attached to a running campaign/seeding run
 *   poll every campaign_content_interval_hours (fast — that is where
 *   money is moving);
 * - baseline tier: everyone else polls every
 *   baseline_content_interval_hours (slow — ambient monitoring);
 * - dormancy (rec 7) still stretches the baseline further: an account
 *   with no content for dormant_after_days never polls more often than
 *   the demoted interval.
 *
 * Guard rails: a never-polled account is always due (a new creator is
 * never blind); intervals <= the cycle spacing mean "every cycle"; the
 * adaptive.enabled flag only disables the DORMANCY stretch, not the plan
 * tiers. Story polls additionally require recent story activity.
 */
class AdaptiveCadence
{
    public function __construct(private readonly CadenceSettings $settings) {}

    public function shouldPollContent(PlatformAccount $account): bool
    {
        $campaignTier = $this->isCampaignExempt($account);

        $intervalHours = $campaignTier
            ? $this->settings->campaignContentIntervalHours()
            : $this->settings->baselineContentIntervalHours();

        // Dormancy stretches the BASELINE tier only — campaign creators
        // always keep full plan resolution.
        if (! $campaignTier
            && config('qds.ingestion.adaptive.enabled')
            && $this->isDormant($account)) {
            $intervalHours = max($intervalHours, (int) config('qds.ingestion.adaptive.demoted_interval_hours'));
        }

        // At or under the cycle spacing = poll every cycle.
        if ($intervalHours <= 6) {
            return true;
        }

        // Any attempt counts (not just successes) so a failing handle is
        // not hammered back up to full cadence by its own failures.
        return ! $this->polledWithin($account, 'content.fetch', $intervalHours);
    }

    /**
     * Story polls additionally require recent story activity: an account
     * with no story seen within the activity window drops to one probe per
     * demoted interval. Stories expire in 24h, so the probe cadence is the
     * catch-up bound for a creator who resumes posting stories.
     */
    public function shouldPollStories(PlatformAccount $account): bool
    {
        if (! config('qds.ingestion.adaptive.enabled')) {
            return true;
        }

        if ($this->isCampaignExempt($account)) {
            return true;
        }

        $windowDays = max(1, (int) config('qds.ingestion.adaptive.story_activity_window_days'));

        $recentStory = Story::query()
            ->where('platform_account_id', $account->id)
            ->where('created_at', '>=', CarbonImmutable::now()->subDays($windowDays))
            ->exists();

        if ($recentStory) {
            return true;
        }

        // Never story-polled yet ⇒ probe now; otherwise once per interval.
        return ! $this->polledWithin(
            $account,
            'stories.fetch',
            max(1, (int) config('qds.ingestion.adaptive.demoted_interval_hours')),
        );
    }

    /**
     * Profile polls run on their own (slower) interval — profile data
     * changes slowly, so the profile call no longer rides every content
     * cycle (product-owner cadence decision, 2026-07-08). Independent of
     * the adaptive enabled flag: it is a cost knob, not dormancy gating.
     * Never-successfully-polled accounts always fetch.
     */
    public function shouldPollProfile(PlatformAccount $account): bool
    {
        $intervalHours = $this->settings->profilePollIntervalHours();

        if ($intervalHours <= 0) {
            return true;
        }

        return ! ProviderCall::query()
            ->where('platform_account_id', $account->id)
            ->where('operation', 'profile.fetch')
            ->where('outcome', CallOutcome::Success->value)
            ->where('started_at', '>=', CarbonImmutable::now()->subHours($intervalHours))
            ->exists();
    }

    private function isDormant(PlatformAccount $account): bool
    {
        $dormantDays = max(1, (int) config('qds.ingestion.adaptive.dormant_after_days'));
        $cutoff = CarbonImmutable::now()->subDays($dormantDays);

        $newestContent = ContentItem::query()
            ->where('platform_account_id', $account->id)
            ->max('published_at');

        if ($newestContent !== null && CarbonImmutable::parse($newestContent) >= $cutoff) {
            return false;
        }

        // No in-window content. Dormant only if we have observed the
        // account long enough to trust that: its first successful content
        // poll predates the dormancy window.
        $firstSuccess = ProviderCall::query()
            ->where('platform_account_id', $account->id)
            ->where('operation', 'content.fetch')
            ->where('outcome', CallOutcome::Success->value)
            ->min('started_at');

        return $firstSuccess !== null && CarbonImmutable::parse($firstSuccess) <= $cutoff;
    }

    private function polledWithin(PlatformAccount $account, string $operation, int $intervalHours): bool
    {
        return ProviderCall::query()
            ->where('platform_account_id', $account->id)
            ->where('operation', $operation)
            ->where('started_at', '>=', CarbonImmutable::now()->subHours(max(1, $intervalHours)))
            ->exists();
    }

    private function isCampaignExempt(PlatformAccount $account): bool
    {
        $activeCampaign = Campaign::query()
            ->whereIn('status', [CampaignStatus::Active->value, CampaignStatus::Planned->value])
            ->whereHas('creators', fn ($q) => $q->whereKey($account->creator_id))
            ->exists();

        if ($activeCampaign) {
            return true;
        }

        return SeedingCampaign::query()
            ->whereIn('status', [
                SeedingCampaignStatus::Planned->value,
                SeedingCampaignStatus::Active->value,
                SeedingCampaignStatus::Shipping->value,
            ])
            ->whereHas('creators', fn ($q) => $q->whereKey($account->creator_id))
            ->exists();
    }
}
