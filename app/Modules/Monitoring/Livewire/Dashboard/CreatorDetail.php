<?php

namespace App\Modules\Monitoring\Livewire\Dashboard;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\PlatformAccount;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Analytics\RollupReader;
use App\Platform\Enrichment\Metrics\DerivedMetricsService;
use App\Platform\Ingestion\SourceRegistry;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use App\Shared\Livewire\Concerns\RunsCreatorMonitoringNow;
use App\Shared\Settings\MonitoringSettingsResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Creator Detail (REQ-M1-005/007, AC-M1-021): creator + platform-account
 * summary, follower growth from ROLLUP-CreatorByPeriod (own-DB snapshots,
 * ADR-0003), DERIVED average/median performance (never PUBLIC — DP-001),
 * recent content and stories, and the creator's mentions with
 * classification, sentiment, verification, and provenance.
 *
 * Posting frequency renders Unavailable (no canonical formula — flagged
 * decision gap); audience demographics render Unavailable (DEF-001);
 * contact auto-extraction renders Unavailable (DEF-002). Engagement trend
 * is DERIVED per ADR-0024 (rolling window, ADR-0025 per-tenant length).
 *
 * Hosts the shared "run monitoring now" lever (RunsCreatorMonitoringNow)
 * so an operator can poll this creator on demand from the detail page.
 */
class CreatorDetail extends Component
{
    use RunsCreatorMonitoringNow;
    use WithPagination;

    public Creator $creator;

    #[Url(except: 'month')]
    public string $grain = 'month';

    #[Url(except: '')]
    public string $platform = '';

    #[Url(except: '')]
    public string $contentType = '';

    public function mount(Creator $creator): void
    {
        $this->authorize('viewAny', Mention::class);

        $this->creator = $creator->load('platformAccounts');
    }

    public function updatingPlatform(): void
    {
        // The content list paginates under the custom page name 'content'
        // (see render()); the default resetPage() targets 'page' and leaves
        // the user stranded on an out-of-range content page (M12).
        $this->resetPage('content');
    }

    public function updatingContentType(): void
    {
        $this->resetPage('content');
    }

    public function render(RollupReader $rollups, DerivedMetricsService $derived, MonitoringSettingsResolver $settings): View
    {
        $accountIds = $this->creator->platformAccounts->pluck('id');
        $platform = Platform::tryFrom($this->platform);
        $contentType = ContentType::tryFrom($this->contentType);

        $series = $rollups->creatorSeries(
            $this->creator->id,
            in_array($this->grain, RollupReader::GRAINS, true) ? $this->grain : 'month',
        );

        $recentContent = ContentItem::query()
            ->whereIn('platform_account_id', $accountIds)
            ->when($platform, fn ($q) => $q->where('platform', $platform->value))
            ->when($contentType, fn ($q) => $q->where('content_type', $contentType->value))
            ->orderByDesc('published_at')
            ->paginate(10, pageName: 'content');

        $recentStories = Story::query()
            ->whereIn('platform_account_id', $accountIds)
            ->orderByDesc('captured_at')
            ->limit(6)
            ->get();

        $mentions = Mention::query()
            ->where(fn ($q) => $q
                ->whereHas('contentItem', fn ($c) => $c->whereIn('platform_account_id', $accountIds))
                ->orWhereHas('story', fn ($s) => $s->whereIn('platform_account_id', $accountIds)))
            ->with(['monitoredSubject', 'campaign', 'contentItem', 'story'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        // DERIVED average/median performance over the recent content page's
        // observed views (MET-AveragePerformance / MET-MedianPerformance).
        $viewAmounts = $recentContent->getCollection()
            ->map(function (ContentItem $item): ?float {
                foreach ($item->public_metrics ?? [] as $metric) {
                    if (in_array($metric->metric, ['views', 'plays'], true)) {
                        return $metric->amount;
                    }
                }

                return null;
            })
            // Keep observed 0.0 views — a bare filter() drops them as falsy
            // and overstates the average/median (M13). Only unobserved
            // (null) content is excluded.
            ->filter(fn (?float $amount) => $amount !== null)
            ->values()
            ->all();

        // ADR-0024 engagement trend: rolling N-day windows (per-tenant N,
        // ADR-0025) over the creator's observed likes+comments.
        $trendWindowDays = $settings->engagementTrendWindowDays();

        // Freshness = the most recent observation of this creator's accounts,
        // taking whichever of two signals is newer:
        //   1. the account-level snapshot heartbeat (rows carry a non-null
        //      platform_account_id) — only written when a follower count exists;
        //   2. the newest external fetch stamped on the accounts themselves
        //      (provenance->fetchedAt), refreshed on every profile pull
        //      regardless of follower count, and excluding hand entries.
        // Signal 1 alone falsely reads "not pulled yet" for a creator whose
        // content was pulled but whose follower count is null/hidden (e.g. a
        // YouTube channel with hidden subscribers), and would disagree with the
        // CRM platform-accounts panel, which reads signal 2. Null only when
        // nothing has ever been pulled (empty set → whereIn([]) → null).
        $dataUpdatedAt = collect([
            MetricSnapshot::query()->whereIn('platform_account_id', $accountIds)->max('captured_at'),
            $this->creator->platformAccounts
                ->reject(fn (PlatformAccount $account) => $account->provenance->source === SourceRegistry::AGENCY_MANUAL_ENTRY)
                ->max(fn (PlatformAccount $account) => $account->provenance->fetchedAt),
        ])->filter()->map(fn ($at) => Carbon::parse($at))->max();

        return view('livewire.monitoring.creator-detail', [
            'series' => $series,
            'latestBucket' => $series->last(),
            'recentContent' => $recentContent,
            'recentStories' => $recentStories,
            'mentions' => $mentions,
            'averagePerformance' => $derived->averagePerformance($viewAmounts, 'average_views'),
            'medianPerformance' => $derived->medianPerformance($viewAmounts, 'median_views'),
            'engagementTrend' => $derived->engagementTrend($this->creator, $trendWindowDays),
            'trendWindowDays' => $trendWindowDays,
            'grains' => RollupReader::GRAINS,
            'platforms' => Platform::cases(),
            'contentTypes' => ContentType::cases(),
            'rollupsRefreshedAt' => $rollups->lastRefreshedAt(),
            'dataUpdatedAt' => $dataUpdatedAt,
        ]);
    }
}
