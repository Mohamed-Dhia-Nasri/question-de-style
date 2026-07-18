<?php

namespace App\Modules\Monitoring\Livewire\Dashboard;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Services\ActiveSeedingCreatorIds;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Support\ProviderHealthPresenter;
use App\Platform\Analytics\RollupReader;
use App\Platform\Enrichment\Review\ReviewQueue;
use App\Platform\Ingestion\Observability\ProviderHealthService;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Monitoring Overview (REQ-M1-012): roster size, ingestion/provider
 * health, new content and active stories, mentions by ENUM-MentionType,
 * pending reviews, and the rollup-backed KPI totals (views, engagement,
 * EMV — all tier-labelled; estimated reach renders ESTIMATED per
 * ADR-0022 when an active reach configuration exists, else unavailable;
 * CONFIRMED reach stays deferred per DEF-003).
 *
 * All filters validate and execute server-side; KPI aggregates come from
 * approved rollups only (ADR-0010). The "Active seeding only" toggle
 * re-scopes the creator-keyed cards to ActiveSeedingCreatorIds
 * (ACTIVE+SHIPPING enrollment); brand-keyed reach/EMV cannot be
 * creator-scoped and render an explanatory unavailable state instead.
 * Provider health is presented in plain English via ProviderHealthPresenter.
 * The deferred-capability panels (open-web listening DEF-006, comment
 * analysis DEF-005) are hidden in the view until those features ship.
 */
class MonitoringOverview extends Component
{
    #[Url(except: '')]
    public string $platform = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: 0)]
    public int $brandId = 0;

    #[Url(except: false)]
    public bool $activeSeedingOnly = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Mention::class);
    }

    private function platformFilter(): ?Platform
    {
        return Platform::tryFrom($this->platform);
    }

    private function dateFilter(string $value): ?Carbon
    {
        try {
            return $value === '' ? null : Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function brandFilter(): ?int
    {
        return $this->brandId > 0 && Brand::query()->whereKey($this->brandId)->exists()
            ? $this->brandId
            : null;
    }

    /**
     * Plain-English summary of the active date filter, shown near the top so
     * "period" is never ambiguous: with no dates the KPI cards count all
     * data, so this reads "all time" rather than a made-up window.
     */
    private function rangeLabel(?Carbon $from, ?Carbon $to): string
    {
        $format = fn (Carbon $date): string => $date->format('j M Y');

        return match (true) {
            $from !== null && $to !== null => $format($from).' – '.$format($to),
            $from !== null => 'from '.$format($from),
            $to !== null => 'until '.$format($to),
            default => 'all time',
        };
    }

    public function render(ReviewQueue $queue, ProviderHealthService $health, RollupReader $rollups): View
    {
        $platform = $this->platformFilter();
        // The KPI totals read week-grain rollups (RollupReader snaps to whole
        // weeks). Snap the whole window to weeks ONCE here so the range label,
        // the live-table cards and the rollup KPIs all describe the same
        // week-aligned window instead of silently disagreeing (M14/M25).
        $from = $this->dateFilter($this->from)?->startOfWeek();
        $to = $this->dateFilter($this->to)?->endOfWeek();
        $brandId = $this->brandFilter();

        // "Active seeding only" (spec 2026-07-17): resolve the enrolled
        // creator set ONCE per render. Cards gate on the boolean — an empty
        // set must filter to zero rows, never fall back to unfiltered.
        $seedingCreatorIds = $this->activeSeedingOnly
            ? app(ActiveSeedingCreatorIds::class)->forCurrentTenant()
            : null;

        $rosterCount = MonitoredSubject::query()
            ->where('subject_type', MonitoredSubjectType::Creator->value)
            ->where('active', true)
            ->when($this->activeSeedingOnly, fn ($q) => $q->whereIn('creator_id', $seedingCreatorIds))
            ->count();

        $newContent = ContentItem::query()
            ->when($platform, fn ($q) => $q->where('platform', $platform->value))
            ->when($from, fn ($q) => $q->where('published_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('published_at', '<=', $to))
            ->when($this->activeSeedingOnly, fn ($q) => $q->whereHas(
                'platformAccount',
                fn ($account) => $account->whereIn('creator_id', $seedingCreatorIds),
            ))
            ->count();

        $activeStories = Story::query()
            ->when($platform, fn ($q) => $q->where('platform', $platform->value))
            ->where(fn ($q) => $q
                ->where('expires_at', '>', now())
                ->orWhere(fn ($inner) => $inner
                    ->whereNull('expires_at')
                    ->where('captured_at', '>=', now()->subDay())))
            ->when($this->activeSeedingOnly, fn ($q) => $q->whereHas(
                'platformAccount',
                fn ($account) => $account->whereIn('creator_id', $seedingCreatorIds),
            ))
            ->count();

        $mentionsByType = Mention::query()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->when($brandId !== null, fn ($q) => $q->whereHas(
                'campaign',
                fn ($c) => $c->where('brand_id', $brandId),
            ))
            ->when($this->activeSeedingOnly, fn ($q) => $q->whereHas(
                'monitoredSubject',
                fn ($subject) => $subject->whereIn('creator_id', $seedingCreatorIds),
            ))
            ->selectRaw('mention_type, count(*) as total')
            ->groupBy('mention_type')
            ->pluck('total', 'mention_type');

        $reviewCounts = $queue->counts();

        $providerRows = ProviderHealthPresenter::rows($health->overview());

        return view('livewire.monitoring.monitoring-overview', [
            'rosterCount' => $rosterCount,
            'rangeLabel' => $this->rangeLabel($from, $to),
            'newContent' => $newContent,
            'activeStories' => $activeStories,
            'mentionsByType' => $mentionsByType,
            'pendingReviews' => array_sum($reviewCounts),
            'reviewCounts' => $reviewCounts,
            'mentionTotals' => $rollups->mentionTotals($from, $to, $brandId),
            'creatorTotals' => $rollups->creatorTotals($from, $to, $seedingCreatorIds),
            'seedingSetEmpty' => $seedingCreatorIds === [],
            'rollupsRefreshedAt' => $rollups->lastRefreshedAt(),
            'providerRows' => $providerRows,
            'platforms' => Platform::cases(),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
