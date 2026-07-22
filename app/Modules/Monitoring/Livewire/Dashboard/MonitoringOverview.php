<?php

namespace App\Modules\Monitoring\Livewire\Dashboard;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Services\ActiveSeedingCreatorIds;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\Story;
use App\Platform\Ingestion\Support\CadenceSettings;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Monitoring Overview (REQ-M1-012): a roster-first dashboard. A compact strip
 * of last-90-day headline totals (posts, views, likes, comments) sits above a
 * paginated grid of the monitored creators themselves — each card shows the
 * person, their platforms with per-platform followers, and an "In active
 * seeding" tag when enrolled in a running seeding (ACTIVE + SHIPPING via
 * ActiveSeedingCreatorIds).
 *
 * Server-side filters: platform, name search, and the "Active seeding only"
 * toggle. Platform + seeding re-scope both the headline totals and the grid;
 * the name search narrows only the grid. The totals count each post ONCE at
 * its latest reading (content_items.public_metrics) — a rollup's per-period
 * bucket sum would double-count cumulative views/likes/comments across weeks;
 * estimated reach and EMV stay on the Exports and per-creator surfaces to keep
 * this overview low on cognitive load.
 */
class MonitoringOverview extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $platform = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: false)]
    public bool $activeSeedingOnly = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Mention::class);
    }

    public function updatingPlatform(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingActiveSeedingOnly(): void
    {
        $this->resetPage();
    }

    private function platformFilter(): ?Platform
    {
        return Platform::tryFrom($this->platform);
    }

    /**
     * The monitored creators for the current page — active CREATOR
     * MonitoredSubjects, narrowed by platform, name search and the seeding
     * toggle, with their platform accounts (and per-platform followers)
     * eager-loaded for the cards.
     *
     * @param  list<int>  $seedingCreatorIds
     * @return LengthAwarePaginator<int, Creator>
     */
    private function creators(?Platform $platform, array $seedingCreatorIds): LengthAwarePaginator
    {
        return Creator::query()
            ->whereHas('monitoredSubjects', fn (Builder $q) => $q
                ->where('subject_type', MonitoredSubjectType::Creator->value)
                ->where('active', true))
            ->when($platform, fn (Builder $q) => $q->whereHas(
                'platformAccounts',
                fn (Builder $a) => $a->where('platform', $platform->value),
            ))
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(
                'display_name', 'ilike', '%'.trim($this->search).'%',
            ))
            ->when($this->activeSeedingOnly, fn (Builder $q) => $q->whereIn('id', $seedingCreatorIds))
            ->with(['platformAccounts' => fn ($q) => $q->orderBy('platform')])
            ->orderBy('display_name')
            ->paginate(12);
    }

    /**
     * Last-90-day headline totals, each post counted ONCE at its latest
     * reading. content_items.public_metrics holds the denormalised current
     * metrics (the ingestion pipeline overwrites it on every re-poll — the same
     * "latest" the creator-detail page reads), so a plain sum over the window's
     * posts is the true total, with none of the per-week double counting a
     * cumulative-metric rollup bucket sum would introduce. Tenant-safe via
     * ContentItem's BelongsToTenant scope; scoped by platform + seeding.
     *
     * @param  list<int>  $seedingCreatorIds
     * @return object{posts: int, views_sum: string|null, likes_sum: string|null, comments_sum: string|null}
     */
    private function rosterTotals(?Platform $platform, array $seedingCreatorIds): object
    {
        // Pull one metric's latest amount out of the public_metrics JSON array.
        // The metric names are fixed literals (never user input) — no injection.
        $amount = fn (string $metric): string => "(select (e->>'amount')::numeric "
            ."from jsonb_array_elements(public_metrics) e where e->>'metric' = '{$metric}' limit 1)";

        /** @var object{posts: int, views_sum: string|null, likes_sum: string|null, comments_sum: string|null} */
        return ContentItem::query()
            ->where('published_at', '>=', now()->subDays(90))
            ->when($platform, fn (Builder $q) => $q->where('platform', $platform->value))
            ->when($this->activeSeedingOnly, fn (Builder $q) => $q->whereHas(
                'platformAccount',
                fn (Builder $account) => $account->whereIn('creator_id', $seedingCreatorIds),
            ))
            ->selectRaw('count(*) as posts')
            ->selectRaw("sum(coalesce({$amount('views')}, {$amount('plays')})) as views_sum")
            ->selectRaw("sum({$amount('likes')}) as likes_sum")
            ->selectRaw("sum({$amount('comments')}) as comments_sum")
            ->first();
    }

    public function render(): View
    {
        $platform = $this->platformFilter();

        // Resolve the enrolled creator set ONCE per render (tenant-scoped). Used
        // both to scope the toggle and to tag cards. An empty set must filter to
        // zero rows, never fall back to unfiltered — so the toggle gates on the
        // boolean, not on this array's truthiness.
        $seedingCreatorIds = app(ActiveSeedingCreatorIds::class)->forCurrentTenant();

        // Headline totals over the last 90 days, scoped by the platform +
        // "active seeding only" filters (the name search narrows only the grid).
        $totals = $this->rosterTotals($platform, $seedingCreatorIds);

        // Freshness = when this tenant's monitored data was last observed. Read
        // from the tenant-owned snapshot/story heartbeats (BelongsToTenant
        // auto-scopes both), never the platform-global ingestion_cycles table —
        // that would leak other tenants' ingest activity (ADR-0019).
        $dataUpdatedAt = collect([
            MetricSnapshot::query()->max('captured_at'),
            Story::query()->max('captured_at'),
        ])->filter()->map(fn ($at) => Carbon::parse($at))->max();

        // The effective poll cadence (operator plan, else config defaults) — so
        // the "how fresh is this?" note always matches actual behaviour rather
        // than a hard-coded guess.
        $cadence = app(CadenceSettings::class);

        return view('livewire.monitoring.monitoring-overview', [
            'totalPosts' => (int) $totals->posts,
            'totalViews' => $totals->views_sum,
            'totalLikes' => $totals->likes_sum,
            'totalComments' => $totals->comments_sum,
            'creators' => $this->creators($platform, $seedingCreatorIds),
            // O(1) "is this creator seeded?" lookup for the card tag.
            'seededLookup' => array_fill_keys($seedingCreatorIds, true),
            'seedingSetEmpty' => $seedingCreatorIds === [],
            'dataUpdatedAt' => $dataUpdatedAt,
            'refreshCampaignHours' => $cadence->campaignContentIntervalHours(),
            'refreshBaselineHours' => $cadence->baselineContentIntervalHours(),
            'platforms' => Platform::cases(),
        ]);
    }
}
