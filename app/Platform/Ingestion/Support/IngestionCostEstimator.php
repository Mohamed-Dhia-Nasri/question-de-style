<?php

namespace App\Platform\Ingestion\Support;

use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Modules\Monitoring\Models\Story;
use App\Shared\Enums\CampaignStatus;
use App\Shared\Enums\Platform;
use App\Shared\Enums\SeedingCampaignStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Monthly Apify cost estimate for the operator's monitoring plan
 * (/monitoring/plan). Prices were verified against the live Apify store
 * on 2026-07-07 for the FREE and STARTER (Bronze) tiers; SCALE (Silver)
 * and BUSINESS (Gold) per-item prices for the Instagram actors are
 * interpolated from the published tier ladders and marked estimated
 * (reviews/PLAN-apify-cost-optimization-2026-07-07.md).
 *
 * This is an ESTIMATE, not a bill: actual spend follows real posting
 * volume; ProviderCall telemetry records the truth once polling runs.
 */
class IngestionCostEstimator
{
    /**
     * Per-unit USD. ig_item ≈ post/reel dataset item; ig_profile = one
     * profile event; tt_result = one TikTok video; tt_filter = the
     * date-filter add-on per result; actor_start = per-run fee (reel +
     * TikTok actors); story_run / story_username per the datavoyantlab
     * actor. 'approx' marks tiers with interpolated Instagram prices.
     */
    private const PRICES = [
        'FREE' => ['ig_item' => 0.0027, 'ig_profile' => 0.0026, 'tt_result' => 0.0037, 'tt_filter' => 0.0013, 'actor_start' => 0.001, 'story_run' => 0.099, 'story_username' => 0.003, 'approx' => false],
        'STARTER' => ['ig_item' => 0.0023, 'ig_profile' => 0.0023, 'tt_result' => 0.0030, 'tt_filter' => 0.0011, 'actor_start' => 0.001, 'story_run' => 0.099, 'story_username' => 0.003, 'approx' => false],
        'SCALE' => ['ig_item' => 0.0019, 'ig_profile' => 0.0019, 'tt_result' => 0.0023, 'tt_filter' => 0.0008, 'actor_start' => 0.001, 'story_run' => 0.099, 'story_username' => 0.003, 'approx' => true],
        'BUSINESS' => ['ig_item' => 0.0017, 'ig_profile' => 0.0017, 'tt_result' => 0.0017, 'tt_filter' => 0.0006, 'actor_start' => 0.001, 'story_run' => 0.099, 'story_username' => 0.003, 'approx' => true],
    ];

    /** Monthly plan fee per Apify tier (USD, includes equal usage credit). */
    public const PLAN_FEES = ['FREE' => 0, 'STARTER' => 29, 'SCALE' => 199, 'BUSINESS' => 999];

    /**
     * Google AI list prices (USD, checked 2026-07): Vision TEXT + LOGO
     * detection are $1.50/1k images each; Video Intelligence TEXT + LOGO
     * ≈ $0.15/min each; Speech-to-Text standard $0.006/15s. Informative
     * only — enrichment bills to Google, not Apify, and never enters
     * estimate()'s total.
     */
    private const VISION_PER_IMAGE = 0.003;

    private const VIDEO_PER_MINUTE = 0.30;

    private const SPEECH_PER_MINUTE = 0.024;

    /**
     * Google Gemini Embedding 2 list price per image (USD) — verified
     * against official pricing 2026-07-19 (visual-matching spec §18):
     * $0.00012 per image, no output charge.
     */
    private const EMBEDDING_PER_IMAGE = 0.00012;

    /** Per-service table assumptions: fresh items enriched and video minutes analyzed per account per month. */
    private const ENRICHED_ITEMS_PER_ACCOUNT_MONTH = 20;

    private const VIDEO_MINUTES_PER_ACCOUNT_MONTH = 4.0;

    /**
     * ESTIMATE: billable frame embeddings per enriched item — the
     * 12-frame budget minus typical quality-filter, dedup, and
     * keyframe-cache savings. Real spend shows on /monitoring/operations.
     */
    private const EMBEDDED_FRAMES_PER_ITEM = 6;

    /**
     * Google Speech-to-Text v2 list price (USD per audio minute, verified
     * 2026-07-20, sub-project D spec §2b.11): $0.016/min, billed per
     * second rounded up, per channel (QDS always sends mono FLAC).
     * v2 has NO free tier — unlike v1's 60 free minutes per month, so
     * the first minute of EVERY audio post is metered once v2 is on.
     */
    private const SPEECH_V2_PER_MINUTE = 0.016;

    /**
     * ESTIMATE: long-audio extension minutes (chunks beyond the first)
     * per campaign/seeding account per month. Only candidate-bearing
     * posts pay for extension (spec §9 tiering) — everyone else stops
     * at the first-minute floor.
     */
    private const SPEECH_V2_EXTENSION_MINUTES_PER_CAMPAIGN_ACCOUNT_MONTH = 6.0;

    /**
     * ESTIMATE (governance, not billing truth — sub-project D spec §11):
     * one Gemini verification request ≈ $0.030 (~10k input tokens at
     * $1.65/M + ~2k output incl. LOW thinking at $9.90/M on the EU rep
     * endpoint), and the visual matcher escalates roughly 15% of
     * enriched items. Real spend shows on /monitoring/operations.
     */
    private const VLM_PER_REQUEST = 0.030;

    private const VLM_ESCALATION_RATE = 0.15;

    /**
     * Current roster composition, straight from the database.
     *
     * @return array{ig_accounts: int, tt_accounts: int, campaign_ig: int, campaign_tt: int, story_active_ig: int}
     */
    public function rosterFromDatabase(): array
    {
        $campaignCreatorIds = Campaign::query()
            ->whereIn('status', [CampaignStatus::Active->value, CampaignStatus::Planned->value])
            ->join('campaign_creator', 'campaign_creator.campaign_id', '=', 'campaigns.id')
            ->pluck('campaign_creator.creator_id')
            ->merge(
                SeedingCampaign::query()
                    ->whereIn('status', [
                        SeedingCampaignStatus::Planned->value,
                        SeedingCampaignStatus::Active->value,
                        SeedingCampaignStatus::Shipping->value,
                    ])
                    ->join('seeding_campaign_creator', 'seeding_campaign_creator.seeding_campaign_id', '=', 'seeding_campaigns.id')
                    ->pluck('seeding_campaign_creator.creator_id'),
            )
            ->unique();

        $counts = PlatformAccount::query()
            ->whereIn('platform', [Platform::Instagram->value, Platform::TikTok->value])
            ->select('platform', DB::raw('count(*) as total'))
            ->groupBy('platform')
            ->pluck('total', 'platform');

        $campaignCounts = PlatformAccount::query()
            ->whereIn('platform', [Platform::Instagram->value, Platform::TikTok->value])
            ->whereIn('creator_id', $campaignCreatorIds)
            ->select('platform', DB::raw('count(*) as total'))
            ->groupBy('platform')
            ->pluck('total', 'platform');

        $storyActive = Story::query()
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(
                max(1, (int) config('qds.ingestion.adaptive.story_activity_window_days')),
            ))
            ->distinct('platform_account_id')
            ->count('platform_account_id');

        return [
            'ig_accounts' => (int) ($counts[Platform::Instagram->value] ?? 0),
            'tt_accounts' => (int) ($counts[Platform::TikTok->value] ?? 0),
            'campaign_ig' => (int) ($campaignCounts[Platform::Instagram->value] ?? 0),
            'campaign_tt' => (int) ($campaignCounts[Platform::TikTok->value] ?? 0),
            'story_active_ig' => $storyActive,
        ];
    }

    /**
     * @param  array{ig_accounts: int, tt_accounts: int, campaign_ig: int, campaign_tt: int, story_active_ig: int}  $roster
     * @param  array{items_per_window_ig?: int, items_per_window_tt?: int, active_pct?: int}  $assumptions
     * @return array{content_ig: float, content_tt: float, profiles: float, stories: float, campaign_refresh: float, total: float, plan_fee: int, approx: bool}
     */
    public function estimate(CadenceSettings $settings, array $roster, array $assumptions = []): array
    {
        $plan = $settings->apifyPlan();
        $p = self::PRICES[$plan] ?? self::PRICES['STARTER'];

        $itemsIg = max(0, $assumptions['items_per_window_ig'] ?? 14);
        $itemsTt = max(0, $assumptions['items_per_window_tt'] ?? 7);
        $activePct = min(100, max(0, $assumptions['active_pct'] ?? 60)) / 100;

        $campaignPolls = $this->pollsPerMonth($settings->campaignContentIntervalHours());
        $baselinePolls = $this->pollsPerMonth($settings->baselineContentIntervalHours());

        // Instagram content: two runs per poll (posts + reels sharing the
        // in-window items) + one reel-run start fee. Dormant baseline
        // accounts bill only the start fee (empty window).
        $igPerPoll = fn (float $items): float => $items * $p['ig_item'] + $p['actor_start'];

        // TikTok content: one run per poll; date-filter add-on per result.
        $ttPerPoll = fn (float $items): float => $p['actor_start'] + $items * ($p['tt_result'] + $p['tt_filter']);

        $baseIg = max(0, $roster['ig_accounts'] - $roster['campaign_ig']);
        $baseTt = max(0, $roster['tt_accounts'] - $roster['campaign_tt']);

        // Split by TIER so the plan page can price each setting on its own
        // row: the campaign-creators control vs the everyone-else control.
        $contentCampaign =
            $roster['campaign_ig'] * $campaignPolls * $igPerPoll($itemsIg)
            + $roster['campaign_tt'] * $campaignPolls * $ttPerPoll($itemsTt);

        $contentBaseline =
            $baseIg * $activePct * $baselinePolls * $igPerPoll($itemsIg)
            + $baseIg * (1 - $activePct) * $baselinePolls * $igPerPoll(0)
            + $baseTt * $activePct * $baselinePolls * $ttPerPoll($itemsTt)
            + $baseTt * (1 - $activePct) * $baselinePolls * $ttPerPoll(1); // quiet-day profile item

        $contentIg =
            $roster['campaign_ig'] * $campaignPolls * $igPerPoll($itemsIg)
            + $baseIg * $activePct * $baselinePolls * $igPerPoll($itemsIg)
            + $baseIg * (1 - $activePct) * $baselinePolls * $igPerPoll(0);

        $contentTt =
            $roster['campaign_tt'] * $campaignPolls * $ttPerPoll($itemsTt)
            + $baseTt * $activePct * $baselinePolls * $ttPerPoll($itemsTt)
            + $baseTt * (1 - $activePct) * $baselinePolls * $ttPerPoll(1);

        // Profiles: Instagram accounts only (TikTok profiles ride the
        // content payload for free).
        $profileInterval = $settings->profilePollIntervalHours();
        $profileFetches = $profileInterval <= 6 ? 120.0 : 720.0 / $profileInterval; // fetches per 30-day month
        $profiles = $roster['ig_accounts'] * $profileFetches * $p['ig_profile'];

        // Stories: batched runs over story-active accounts only.
        $storiesPerDay = $settings->storiesPerDay();
        $storyActive = min($roster['story_active_ig'], $roster['ig_accounts']);
        $stories = $storiesPerDay <= 0 || $storyActive === 0 ? 0.0
            : 30 * $storiesPerDay * (
                ceil($storyActive / max(1, (int) config('qds.ingestion.story_batch_size'))) * $p['story_run']
                + $storyActive * $p['story_username']
            );

        // Campaign-linked direct-URL refresh: daily, capped.
        $campaignRefresh = config('qds.ingestion.campaign_refresh.enabled')
            ? 30 * min(100, (int) config('qds.ingestion.campaign_refresh.max_urls_per_run')) * $p['ig_item'] * 0.3
            : 0.0; // ~30% of the cap typically eligible — rough placeholder

        $total = $contentIg + $contentTt + $profiles + $stories + $campaignRefresh;

        return [
            'content_ig' => round($contentIg, 2),
            'content_tt' => round($contentTt, 2),
            'content_campaign' => round($contentCampaign, 2),
            'content_baseline' => round($contentBaseline, 2),
            'profiles' => round($profiles, 2),
            'stories' => round($stories, 2),
            'campaign_refresh' => round($campaignRefresh, 2),
            'total' => round($total, 2),
            'plan_fee' => self::PLAN_FEES[$plan] ?? 0,
            'approx' => (bool) $p['approx'],
        ];
    }

    /**
     * Per-service price sheet for the plan page: what every external data
     * service bills per unit and what that works out to per monitored
     * account per month under the current selection. Apify rows reuse the
     * estimate() figures; Google AI rows use published list prices with
     * the enrichment sweep's batch size as the monthly volume ceiling.
     * Services that are switched off still show their would-be cost,
     * flagged inactive, so the operator can price the decision.
     *
     * @param  array{ig_accounts: int, tt_accounts: int, campaign_ig: int, campaign_tt: int, story_active_ig: int}  $roster
     * @param  array{content_ig: float, content_tt: float, profiles: float, stories: float, campaign_refresh: float}  $estimate
     * @return list<array{service: string, detail: string, unit: string, monthly: float, per_creator: float|null, active: bool, note: string|null}>
     */
    public function perService(CadenceSettings $settings, array $roster, array $estimate): array
    {
        $p = self::PRICES[$settings->apifyPlan()] ?? self::PRICES['STARTER'];

        $storiesOn = $settings->storiesPerDay() > 0;
        $storyActive = min($roster['story_active_ig'], $roster['ig_accounts']);
        $refreshOn = (bool) config('qds.ingestion.campaign_refresh.enabled');
        $campaignAccounts = $roster['campaign_ig'] + $roster['campaign_tt'];
        $allAccounts = $roster['ig_accounts'] + $roster['tt_accounts'];

        // Google AI volume: each fresh item is enriched once, and the sweep
        // batch caps a month at batch × 4 sweeps/day (default 6-hourly
        // cron) × 30 days — the enrichment pipeline's own cost brake.
        $sweepCeiling = 30 * 4 * max(0, (int) config('qds.enrichment.sweep_batch'));
        $enrichedItems = min($allAccounts * self::ENRICHED_ITEMS_PER_ACCOUNT_MONTH, $sweepCeiling);
        $enrichmentOn = (bool) config('qds.enrichment.enabled');
        $visionOn = $enrichmentOn && (string) config('services.google_vision.api_key') !== '';
        $videoOn = $enrichmentOn && (string) config('services.google_video_intelligence.api_key') !== '';
        $speechOn = $enrichmentOn && (string) config('services.google_speech.api_key') !== '';
        $videoMinutes = min($allAccounts * self::VIDEO_MINUTES_PER_ACCOUNT_MONTH, $sweepCeiling * self::VIDEO_MINUTES_PER_ACCOUNT_MONTH / max(1, self::ENRICHED_ITEMS_PER_ACCOUNT_MONTH));
        // Mirrors GoogleServiceAccountTokenProvider::isConfigured() — derived
        // from config presence here, never by instantiating the provider.
        $embeddingsCredentialsPath = (string) config('services.google_embeddings.credentials_path');
        $embeddingsConfigured = $embeddingsCredentialsPath !== ''
            && is_readable($embeddingsCredentialsPath)
            && (string) config('services.google_embeddings.project_id') !== '';
        $visualMatchSwitchOn = (bool) config('qds.enrichment.visual_match.enabled');
        $visualMatchOn = $enrichmentOn && $visualMatchSwitchOn && $embeddingsConfigured;
        $embeddedImages = $enrichedItems * self::EMBEDDED_FRAMES_PER_ITEM;

        // Speech (sub-project D): when the v2 switch is ON the row prices
        // the multilingual chirp_3 path — a first-minute floor for EVERY
        // audio post (v2 has no free tier) plus tiered long-audio
        // extension for candidate-bearing creators. Switch OFF keeps the
        // legacy v1 presentation byte-identical (rollback purity, §9).
        $speechV2SwitchOn = (bool) config('qds.enrichment.speech.v2_enabled');
        $speechV2CredentialsPath = (string) config('services.google_speech_v2.credentials_path');
        $speechV2Configured = $speechV2CredentialsPath !== ''
            && is_readable($speechV2CredentialsPath)
            && (string) config('services.google_speech_v2.project_id') !== '';
        $speechV2On = $enrichmentOn && $speechV2SwitchOn && $speechV2Configured;
        $speechV2Minutes = $videoMinutes
            + $campaignAccounts * self::SPEECH_V2_EXTENSION_MINUTES_PER_CAMPAIGN_ACCOUNT_MONTH;

        // VLM verification (sub-project D): only posts the visual matcher
        // escalates are verified; active needs all four of enrichment +
        // VLM switch + VLM credentials + visual matching on (the
        // escalation source — D requires C, spec §4).
        $vlmSwitchOn = (bool) config('qds.enrichment.vlm.enabled');
        $vlmCredentialsPath = (string) config('services.google_vlm.credentials_path');
        $vlmConfigured = $vlmCredentialsPath !== ''
            && is_readable($vlmCredentialsPath)
            && (string) config('services.google_vlm.project_id') !== '';
        $vlmOn = $enrichmentOn && $vlmSwitchOn && $vlmConfigured && $visualMatchSwitchOn;
        $vlmRequests = $enrichedItems * self::VLM_ESCALATION_RATE;

        return [
            [
                'service' => 'Instagram posts & reels',
                'detail' => 'New posts and reels, plus refreshed likes & views',
                'unit' => $this->usd($p['ig_item']).' per post/reel + '.$this->usd($p['actor_start']).' per reel run',
                'monthly' => $estimate['content_ig'],
                'per_creator' => $this->perAccount($estimate['content_ig'], $roster['ig_accounts']),
                'active' => true,
                'note' => null,
            ],
            [
                'service' => 'TikTok videos',
                'detail' => 'New videos, plus refreshed plays & likes',
                'unit' => $this->usd($p['tt_result'] + $p['tt_filter']).' per video + '.$this->usd($p['actor_start']).' per run',
                'monthly' => $estimate['content_tt'],
                'per_creator' => $this->perAccount($estimate['content_tt'], $roster['tt_accounts']),
                'active' => true,
                'note' => null,
            ],
            [
                'service' => 'Instagram stories',
                'detail' => 'Catching stories before their 24-hour expiry',
                'unit' => $this->usd($p['story_run']).' per batch of '.max(1, (int) config('qds.ingestion.story_batch_size')).' + '.$this->usd($p['story_username']).' per account',
                'monthly' => $estimate['stories'],
                'per_creator' => $this->perAccount($estimate['stories'], $storyActive),
                'active' => $storiesOn,
                'note' => $storiesOn
                    ? 'Only accounts with recent stories are checked — '.number_format($storyActive).' today.'
                    : 'Off — story collection is disabled above.',
            ],
            [
                'service' => 'Follower counts & bios',
                'detail' => 'Profile snapshots behind the follower-growth charts',
                'unit' => $this->usd($p['ig_profile']).' per profile check',
                'monthly' => $estimate['profiles'],
                'per_creator' => $this->perAccount($estimate['profiles'], $roster['ig_accounts']),
                'active' => true,
                'note' => 'TikTok profiles ride along with the video collection for free.',
            ],
            [
                'service' => 'Campaign post refresh',
                'detail' => 'Daily re-check of older campaign posts so results stay current',
                'unit' => $this->usd($p['ig_item']).' per post refreshed',
                'monthly' => $estimate['campaign_refresh'],
                'per_creator' => $this->perAccount($estimate['campaign_refresh'], $campaignAccounts),
                'active' => $refreshOn,
                'note' => $refreshOn ? 'Runs automatically once a day — not a setting.' : 'Off by configuration.',
            ],
            [
                'service' => 'YouTube channels & videos',
                'detail' => 'Collected through the official YouTube API',
                'unit' => 'Free within the official API quota',
                'monthly' => 0.0,
                'per_creator' => 0.0,
                'active' => true,
                'note' => null,
            ],
            [
                'service' => 'Image text & logos (OCR)',
                'detail' => 'Google Vision reads brand names and logos out of images',
                'unit' => $this->usd(self::VISION_PER_IMAGE).' per image (text + logo detection)',
                'monthly' => round($enrichedItems * self::VISION_PER_IMAGE, 2),
                'per_creator' => $this->perAccount($enrichedItems * self::VISION_PER_IMAGE, $allAccounts),
                'active' => $visionOn,
                'note' => match (true) {
                    $visionOn => 'Billed by Google, not Apify — not part of the total above.',
                    $enrichmentOn => 'Off — add a Google Vision API key to switch this on.',
                    default => 'Off — AI enrichment is disabled.',
                },
            ],
            [
                'service' => 'Video text & logos',
                'detail' => 'Google Video Intelligence deep pass over reels & videos',
                'unit' => '$0.30 per video minute (text + logo detection)',
                'monthly' => round($videoMinutes * self::VIDEO_PER_MINUTE, 2),
                'per_creator' => $this->perAccount($videoMinutes * self::VIDEO_PER_MINUTE, $allAccounts),
                'active' => $videoOn,
                'note' => match (true) {
                    $videoOn => 'Billed by Google, not Apify — not part of the total above.',
                    $enrichmentOn => 'Optional deep pass — add a Google Video Intelligence API key to switch it on.',
                    default => 'Optional deep pass — off while AI enrichment is disabled.',
                },
            ],
            $speechV2SwitchOn
                ? [
                    'service' => 'Spoken brand mentions',
                    'detail' => 'Google Speech-to-Text v2 hears brand names in any language',
                    'unit' => '$0.016 per audio minute (chirp_3, EU, language auto-detect)',
                    'monthly' => round($speechV2Minutes * self::SPEECH_V2_PER_MINUTE, 2),
                    'per_creator' => $this->perAccount($speechV2Minutes * self::SPEECH_V2_PER_MINUTE, $allAccounts),
                    'active' => $speechV2On,
                    'note' => match (true) {
                        $speechV2On => 'Billed by Google, not Apify — every video with audio pays for its first minute (v2 has no free tier); longer videos from creators with an active seeding transcribe up to '.max(1, (int) config('qds.enrichment.speech.max_minutes')).' minutes.',
                        ! $enrichmentOn => 'Off — AI enrichment is disabled.',
                        default => 'Off — add Google Speech v2 service-account credentials to switch this on.',
                    },
                ]
                : [
                    'service' => 'Spoken brand mentions',
                    'detail' => 'Google Speech-to-Text hears brand names in video audio',
                    'unit' => '$0.024 per audio minute (first minute of each video)',
                    'monthly' => round($videoMinutes * self::SPEECH_PER_MINUTE, 2),
                    'per_creator' => $this->perAccount($videoMinutes * self::SPEECH_PER_MINUTE, $allAccounts),
                    'active' => $speechOn,
                    'note' => match (true) {
                        $speechOn => 'Billed by Google, not Apify — not part of the total above.',
                        $enrichmentOn => 'Off — add a Google Speech API key to switch it on. Needs ffmpeg on the server.',
                        default => 'Off — AI enrichment is disabled.',
                    },
                ],
            [
                'service' => 'Visual product matching (embeddings)',
                'detail' => 'Gemini image embeddings compare video frames with product reference photos',
                'unit' => '$0.00012 per image embedded',
                'monthly' => round($embeddedImages * self::EMBEDDING_PER_IMAGE, 2),
                'per_creator' => $this->perAccount($embeddedImages * self::EMBEDDING_PER_IMAGE, $allAccounts),
                'active' => $visualMatchOn,
                'note' => match (true) {
                    $visualMatchOn => 'Billed by Google, not Apify — frame embeddings are cached, so real spend is usually lower.',
                    ! $enrichmentOn => 'Off — AI enrichment is disabled.',
                    ! $visualMatchSwitchOn => 'Off — visual product matching is disabled (kill switch).',
                    default => 'Off — add Google Embeddings service-account credentials to switch this on.',
                },
            ],
            [
                'service' => 'VLM verification (Gemini)',
                'detail' => 'Gemini double-checks escalated posts against the product catalog',
                'unit' => '$0.030 per Gemini verification request',
                'monthly' => round($vlmRequests * self::VLM_PER_REQUEST, 2),
                'per_creator' => $this->perAccount($vlmRequests * self::VLM_PER_REQUEST, $allAccounts),
                'active' => $vlmOn,
                'note' => match (true) {
                    $vlmOn => 'Billed by Google, not Apify — only posts the visual matcher escalates are verified, at most 3 calls each.',
                    ! $enrichmentOn => 'Off — AI enrichment is disabled.',
                    ! $vlmSwitchOn => 'Off — VLM verification is disabled (kill switch).',
                    ! $visualMatchSwitchOn => 'Off — needs visual product matching (its escalation source) switched on first.',
                    default => 'Off — add Google VLM service-account credentials to switch this on.',
                },
            ],
        ];
    }

    /** Content polls per month for an interval (30-day month; <=6h = every 6h cycle). */
    private function pollsPerMonth(int $intervalHours): float
    {
        return $intervalHours <= 6 ? 120.0 : (24.0 / $intervalHours) * 30.0;
    }

    /** Monthly service cost split per covered account; null when the service covers nobody. */
    private function perAccount(float $monthly, int $accounts): ?float
    {
        return $accounts > 0 ? round($monthly / $accounts, 4) : null;
    }

    /** "$0.0023" — trailing zeros trimmed so unit prices read like the Apify store. */
    private function usd(float $value): string
    {
        return '$'.rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
