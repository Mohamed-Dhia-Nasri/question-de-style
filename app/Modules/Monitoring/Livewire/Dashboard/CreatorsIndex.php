<?php

namespace App\Modules\Monitoring\Livewire\Dashboard;

use App\Modules\CRM\Models\Creator;
use App\Modules\Monitoring\Models\Mention;
use App\Platform\Analytics\RollupReader;
use App\Shared\Enums\MonitoredSubjectType;
use App\Shared\Enums\Platform;
use App\Shared\Livewire\Concerns\WithDataTable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Monitored creators (the roster, REQ-M1-001): paginated, sortable,
 * debounced-search list of creators tracked by an active CREATOR
 * MonitoredSubject, with latest rollup stats per creator (rollups only —
 * ADR-0010). Server-side filters: platform, search.
 */
class CreatorsIndex extends Component
{
    use WithDataTable;

    #[Url(except: '')]
    public string $platform = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Mention::class);
    }

    public function updatingPlatform(): void
    {
        $this->resetPage();
    }

    /** @return list<string> */
    protected function sortableColumns(): array
    {
        return ['display_name', 'relationship_status'];
    }

    /** @return list<int|string> */
    protected function currentPageIds(): array
    {
        return $this->creators()->getCollection()->pluck('id')->all();
    }

    /** @return LengthAwarePaginator<int, Creator> */
    private function creators(): LengthAwarePaginator
    {
        $platform = Platform::tryFrom($this->platform);

        $query = Creator::query()
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
            ->with('platformAccounts');

        if ($this->sortField === '') {
            $query->orderBy('display_name');
        } else {
            $query = $this->applySort($query);
        }

        return $query->paginate($this->perPage());
    }

    public function render(RollupReader $rollups): View
    {
        $creators = $this->creators();

        $stats = $rollups
            ->latestCreatorBuckets('month', $creators->getCollection()->pluck('id')->all())
            ->keyBy('creator_id');

        return view('livewire.monitoring.creators-index', [
            'creators' => $creators,
            'stats' => $stats,
            'platforms' => Platform::cases(),
        ]);
    }
}
