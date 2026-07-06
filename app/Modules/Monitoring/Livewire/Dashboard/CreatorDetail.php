<?php

namespace App\Modules\Monitoring\Livewire\Dashboard;

use App\Modules\CRM\Models\Creator;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Analytics\RollupReader;
use App\Platform\Enrichment\Metrics\DerivedMetricsService;
use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;
use Illuminate\Contracts\View\View;
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
 * contact auto-extraction renders Unavailable (DEF-002).
 */
class CreatorDetail extends Component
{
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
        $this->resetPage();
    }

    public function updatingContentType(): void
    {
        $this->resetPage();
    }

    public function render(RollupReader $rollups, DerivedMetricsService $derived): View
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
            ->filter()
            ->values()
            ->all();

        return view('livewire.monitoring.creator-detail', [
            'series' => $series,
            'latestBucket' => $series->last(),
            'recentContent' => $recentContent,
            'recentStories' => $recentStories,
            'mentions' => $mentions,
            'averagePerformance' => $derived->averagePerformance($viewAmounts, 'average_views'),
            'medianPerformance' => $derived->medianPerformance($viewAmounts, 'median_views'),
            'grains' => RollupReader::GRAINS,
            'platforms' => Platform::cases(),
            'contentTypes' => ContentType::cases(),
            'rollupsRefreshedAt' => $rollups->lastRefreshedAt(),
        ]);
    }
}
