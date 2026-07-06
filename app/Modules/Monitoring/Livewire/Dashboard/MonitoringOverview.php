<?php

namespace App\Modules\Monitoring\Livewire\Dashboard;

use App\Modules\CRM\Models\Brand;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\Story;
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
 * EMV — all tier-labelled; estimated reach renders Unavailable until a
 * canonical estimation method exists, and CONFIRMED reach is DEF-003).
 *
 * All filters validate and execute server-side; KPI aggregates come from
 * approved rollups only (ADR-0010). Deferred capabilities (open-web
 * listening DEF-006, comment analysis DEF-005) render "unavailable".
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

    public function render(ReviewQueue $queue, ProviderHealthService $health, RollupReader $rollups): View
    {
        $platform = $this->platformFilter();
        $from = $this->dateFilter($this->from);
        $to = $this->dateFilter($this->to)?->endOfDay();
        $brandId = $this->brandFilter();

        $rosterCount = MonitoredSubject::query()
            ->where('subject_type', MonitoredSubjectType::Creator->value)
            ->where('active', true)
            ->count();

        $newContent = ContentItem::query()
            ->when($platform, fn ($q) => $q->where('platform', $platform->value))
            ->when($from, fn ($q) => $q->where('published_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('published_at', '<=', $to))
            ->count();

        $activeStories = Story::query()
            ->when($platform, fn ($q) => $q->where('platform', $platform->value))
            ->where(fn ($q) => $q
                ->where('expires_at', '>', now())
                ->orWhere(fn ($inner) => $inner
                    ->whereNull('expires_at')
                    ->where('captured_at', '>=', now()->subDay())))
            ->count();

        $mentionsByType = Mention::query()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->when($brandId !== null, fn ($q) => $q->whereHas(
                'campaign',
                fn ($c) => $c->where('brand_id', $brandId),
            ))
            ->selectRaw('mention_type, count(*) as total')
            ->groupBy('mention_type')
            ->pluck('total', 'mention_type');

        $providerHealth = $health->overview();
        $failingProviders = collect($providerHealth)
            ->filter(fn (array $p): bool => $p['status'] === 'FAILING' || $p['consecutive_failures'] > 0);
        $staleProviders = collect($providerHealth)
            ->filter(fn (array $p): bool => $p['stale_data_warning'] === true);

        return view('livewire.monitoring.monitoring-overview', [
            'rosterCount' => $rosterCount,
            'newContent' => $newContent,
            'activeStories' => $activeStories,
            'mentionsByType' => $mentionsByType,
            'pendingReviews' => array_sum($queue->counts()),
            'reviewCounts' => $queue->counts(),
            'mentionTotals' => $rollups->mentionTotals($from, $to, $brandId),
            'creatorTotals' => $rollups->creatorTotals($from, $to),
            'rollupsRefreshedAt' => $rollups->lastRefreshedAt(),
            'failingProviders' => $failingProviders,
            'staleProviders' => $staleProviders,
            'platforms' => Platform::cases(),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
