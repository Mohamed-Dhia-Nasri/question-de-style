<?php

namespace App\Modules\Monitoring\Livewire\Hashtags;

use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\Monitoring\Models\ContentHashtag;
use App\Modules\Monitoring\Models\HashtagList;
use App\Platform\Enrichment\Hashtags\HashtagNormalizer;
use App\Platform\Enrichment\Support\HashtagScope;
use App\Shared\Audit\AuditLogger;
use App\Shared\Livewire\Concerns\WithDataTable;
use App\Shared\Tenancy\TenantRule;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Configured hashtag lists (SVC-EnrichmentAI matching input) — the operator
 * registry of campaign / brand / product / agency hashtags that
 * HashtagMatcher aligns extracted content hashtags against. A registered
 * hashtag is ATTRIBUTION EVIDENCE ONLY: it may strengthen a classification
 * but never proves PAID/SEEDED alone (ADR-0008, DP-003).
 *
 * UsersIndex reference CRUD pattern (ADR-0012). Page needs monitoring.view;
 * every mutation re-authorizes through HashtagListPolicy (monitoring.manage).
 * Deactivating (not deleting) is the primary retire affordance: the matcher
 * only consults active entries, while resolved ambiguous matches keep their
 * history. Deleting nulls those resolution pointers (FK nullOnDelete).
 */
class HashtagListsIndex extends Component
{
    use WithDataTable;

    // --- create/edit form state ---
    public bool $showForm = false;

    public ?int $editingHashtagListId = null;

    public string $hashtag_value = '';

    public string $hashtag_scope = '';

    public string $hashtag_campaign_id = '';

    public string $hashtag_brand_id = '';

    public string $hashtag_product_label = '';

    // --- delete confirmation state ---
    public ?int $confirmingDeleteId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', HashtagList::class);

        if ($this->sortField === '') {
            $this->sortField = 'normalized';
        }
    }

    protected function sortableColumns(): array
    {
        return ['normalized', 'scope', 'active', 'created_at', 'content_count'];
    }

    protected function currentPageIds(): array
    {
        return $this->hashtagsQuery()->paginate($this->perPage())->pluck('id')->all();
    }

    /** @return Builder<HashtagList> */
    protected function hashtagsQuery(): Builder
    {
        return $this->applySort(
            HashtagList::query()
                ->with(['campaign', 'brand', 'creator'])
                ->select('hashtag_lists.*')
                // Usage: distinct roster content items whose extracted
                // hashtags include this normalized form — a real measured
                // count (zero IS a measurement, no tier envelope needed).
                ->selectSub(
                    ContentHashtag::query()
                        ->selectRaw('count(distinct content_item_id)')
                        ->whereColumn('content_hashtags.normalized', 'hashtag_lists.normalized'),
                    'content_count'
                )
                ->when($this->search !== '', function (Builder $query) {
                    $query->where(function (Builder $inner) {
                        $inner->where('hashtag', 'ilike', '%'.$this->search.'%')
                            ->orWhere('normalized', 'ilike', '%'.HashtagNormalizer::normalize($this->search).'%');
                    });
                })
        );
    }

    // --- create / edit -----------------------------------------------------

    public function create(): void
    {
        $this->authorize('create', HashtagList::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $hashtagListId): void
    {
        $entry = HashtagList::findOrFail($hashtagListId);

        $this->authorize('update', $entry);

        $this->resetForm();
        $this->editingHashtagListId = $entry->id;
        $this->hashtag_value = $entry->hashtag;
        $this->hashtag_scope = $entry->scope->value;
        $this->hashtag_campaign_id = $entry->campaign_id !== null ? (string) $entry->campaign_id : '';
        $this->hashtag_brand_id = $entry->brand_id !== null ? (string) $entry->brand_id : '';
        $this->hashtag_product_label = $entry->product_label ?? '';
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $editing = $this->editingHashtagListId !== null;
        $entry = $editing ? HashtagList::findOrFail($this->editingHashtagListId) : null;

        $this->authorize($editing ? 'update' : 'create', $entry ?? HashtagList::class);

        $validated = $this->validate([
            'hashtag_value' => ['required', 'string', 'max:150'],
            // Closed operational vocabulary (hashtag_lists_scope_check).
            'hashtag_scope' => ['required', Rule::in(array_column(HashtagScope::cases(), 'value'))],
            'hashtag_campaign_id' => ['nullable', 'integer', TenantRule::exists('campaigns', 'id')],
            'hashtag_brand_id' => ['nullable', 'integer', TenantRule::exists('brands', 'id')],
            'hashtag_product_label' => ['nullable', 'string', 'max:255'],
        ]);

        $scope = HashtagScope::from($validated['hashtag_scope']);
        $normalized = HashtagNormalizer::normalize($validated['hashtag_value']);

        if ($normalized === '') {
            throw ValidationException::withMessages([
                'hashtag_value' => 'The hashtag must contain characters beyond "#".',
            ]);
        }

        // Each scope names exactly its owner (hashtag_lists_scope_target_check):
        // fields outside the scope are dropped, missing owners are refused.
        $campaignId = $scope === HashtagScope::Campaign
            ? (($validated['hashtag_campaign_id'] ?? '') !== '' ? (int) $validated['hashtag_campaign_id'] : null)
            : null;
        $brandId = in_array($scope, [HashtagScope::Brand, HashtagScope::Product], true)
            ? (($validated['hashtag_brand_id'] ?? '') !== '' ? (int) $validated['hashtag_brand_id'] : null)
            : null;
        $productLabel = $scope === HashtagScope::Product
            ? (trim($validated['hashtag_product_label'] ?? '') !== '' ? trim($validated['hashtag_product_label']) : null)
            : null;

        if ($scope === HashtagScope::Campaign && $campaignId === null) {
            throw ValidationException::withMessages([
                'hashtag_campaign_id' => 'A campaign-scoped hashtag names its campaign.',
            ]);
        }

        if (in_array($scope, [HashtagScope::Brand, HashtagScope::Product], true) && $brandId === null) {
            throw ValidationException::withMessages([
                'hashtag_brand_id' => 'A brand- or product-scoped hashtag names its brand.',
            ]);
        }

        if ($scope === HashtagScope::Product && $productLabel === null) {
            throw ValidationException::withMessages([
                'hashtag_product_label' => 'A product-scoped hashtag names its product.',
            ]);
        }

        // Friendly duplicate check; the partial unique index
        // (hashtag_lists_entry_unique) stays the concurrent-write backstop.
        $duplicate = HashtagList::query()
            ->where('normalized', $normalized)
            ->where('scope', $scope->value)
            ->where('campaign_id', $campaignId)
            ->where('brand_id', $brandId)
            ->where('product_label', $productLabel)
            ->when($editing, fn (Builder $query) => $query->whereKeyNot($entry->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'hashtag_value' => 'This hashtag is already registered for that scope.',
            ]);
        }

        $attributes = [
            'scope' => $scope,
            'campaign_id' => $campaignId,
            'brand_id' => $brandId,
            'product_label' => $productLabel,
            'hashtag' => trim($validated['hashtag_value']),
            'normalized' => $normalized,
        ];

        try {
            if ($editing) {
                $entry->update($attributes);
            } else {
                $entry = HashtagList::create($attributes + [
                    'active' => true,
                    'created_by' => Auth::id(),
                ]);
            }
        } catch (QueryException $e) {
            // The unique index caught a concurrent duplicate.
            if (str_contains($e->getMessage(), 'hashtag_lists_entry_unique')) {
                throw ValidationException::withMessages([
                    'hashtag_value' => 'This hashtag is already registered for that scope.',
                ]);
            }

            throw $e;
        }

        $audit->record($editing ? 'hashtag_list.updated' : 'hashtag_list.created', $entry, [
            'normalized' => $entry->normalized,
            'scope' => $entry->scope->value,
        ]);

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', type: 'success', message: $editing ? 'Hashtag updated.' : 'Hashtag registered.');
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    // --- activate / deactivate ----------------------------------------------

    public function toggleActive(int $hashtagListId, AuditLogger $audit): void
    {
        $entry = HashtagList::findOrFail($hashtagListId);

        $this->authorize('update', $entry);

        $entry->update(['active' => ! $entry->active]);

        $audit->record($entry->active ? 'hashtag_list.activated' : 'hashtag_list.deactivated', $entry, [
            'normalized' => $entry->normalized,
        ]);

        $this->dispatch('notify', type: 'success', message: $entry->active ? 'Hashtag activated.' : 'Hashtag deactivated — the matcher will ignore it.');
    }

    // --- delete ------------------------------------------------------------

    public function confirmDelete(int $hashtagListId): void
    {
        $this->authorize('delete', HashtagList::findOrFail($hashtagListId));

        $this->confirmingDeleteId = $hashtagListId;
    }

    public function delete(AuditLogger $audit): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $entry = HashtagList::findOrFail($this->confirmingDeleteId);

        $this->authorize('delete', $entry);

        DB::transaction(fn () => $entry->delete());

        $audit->record('hashtag_list.deleted', $entry, [
            'normalized' => $entry->normalized,
            'scope' => $entry->scope->value,
        ]);

        $this->confirmingDeleteId = null;
        $this->clearSelection();
        $this->clampPage();
        $this->dispatch('notify', type: 'success', message: 'Hashtag deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    /** After deletes/filter-affecting mutations, leave no out-of-range page. */
    protected function clampPage(): void
    {
        if ($this->getPage() > 1 && $this->hashtagsQuery()->paginate($this->perPage())->isEmpty()) {
            $this->resetPage();
        }
    }

    // -------------------------------------------------------------------------

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingHashtagListId = null;
        $this->hashtag_value = '';
        $this->hashtag_scope = '';
        $this->hashtag_campaign_id = '';
        $this->hashtag_brand_id = '';
        $this->hashtag_product_label = '';
    }

    public function render(): View
    {
        return view('livewire.monitoring.hashtag-lists-index', [
            'entries' => $this->hashtagsQuery()->paginate($this->perPage()),
            'scopes' => HashtagScope::cases(),
            'campaigns' => Campaign::orderBy('name')->get(),
            'brands' => Brand::orderBy('name')->get(),
        ]);
    }
}
