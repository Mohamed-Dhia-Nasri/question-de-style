<?php

namespace App\Shared\Livewire\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Shared foundation for Livewire data tables: debounced search, whitelisted
 * sorting, pagination, per-page selection, bulk row selection, and
 * query-string state — the pattern every Module 3 CRUD screen follows
 * (ADR-0012: tables are hand-built Livewire components, no Filament).
 *
 * Usage:
 *  - the component defines `sortableColumns(): array` (the ONLY columns that
 *    may ever reach ORDER BY — sort input is user-controlled via the query
 *    string, so it is validated on read, not just in sortBy());
 *  - the component defines `currentPageIds(): array` for page-level bulk
 *    selection;
 *  - apply `applySort($query)` and paginate with `$this->perPage()`;
 *  - reset dependent state in `updated<Property>` hooks as needed.
 */
trait WithDataTable
{
    use WithPagination;

    /** @var list<int> */
    protected const PER_PAGE_OPTIONS = [10, 25, 50];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sortField = '';

    #[Url(except: 'asc')]
    public string $sortDirection = 'asc';

    #[Url(except: 10)]
    public int $perPage = 10;

    /** @var list<string> selected row ids (Livewire checkboxes bind strings) */
    public array $selected = [];

    public bool $selectPage = false;

    /** @return list<string> columns allowed in ORDER BY */
    abstract protected function sortableColumns(): array;

    /** @return list<int|string> ids of the rows on the current page */
    abstract protected function currentPageIds(): array;

    public function sortBy(string $field): void
    {
        if (! in_array($field, $this->sortableColumns(), true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    /**
     * Validated ORDER BY — tolerates tampered query-string values by falling
     * back to the primary key.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function applySort(Builder $query): Builder
    {
        $field = in_array($this->sortField, $this->sortableColumns(), true)
            ? $this->sortField
            : $query->getModel()->getKeyName();

        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($field, $direction);
    }

    protected function perPage(): int
    {
        return in_array($this->perPage, self::PER_PAGE_OPTIONS, true) ? $this->perPage : 10;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedPage(): void
    {
        $this->clearSelection();
    }

    public function updatedSelectPage(bool $value): void
    {
        $this->selected = $value
            ? array_map('strval', $this->currentPageIds())
            : [];
    }

    public function updatedSelected(): void
    {
        $this->selectPage = false;
    }

    protected function clearSelection(): void
    {
        $this->selected = [];
        $this->selectPage = false;
    }
}
