<?php

namespace App\Modules\CRM\Livewire\Concerns;

use App\Modules\CRM\Exceptions\BrandRestrictionViolation;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Services\BrandRestrictionGuard;
use App\Modules\CRM\Services\CreatorWriter;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enums\Platform;
use App\Shared\Enums\RelationshipStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared roster picker for the campaign / seeding-run creators panels (CRM
 * UX Stage C, F08): a searchable, platform-filtered multi-select over
 * ENT-Creator with an inline "new creator" path. Restricted creators stay
 * SELECTABLE but are skipped at save with a named notice; the AC-M3-007 hard
 * filter is still enforced per creator through
 * BrandRestrictionGuard::assertNotRestricted, so bulk matching never becomes
 * a second, drifting source of truth.
 *
 * Hosts wire the four seams: the owner (authorization + refresh target), the
 * brand the restriction is checked against, the BelongsToMany the creators
 * attach through (it stamps the pivot tenant_id), and the audit event name.
 */
trait ManagesCreatorRoster
{
    public bool $showPicker = false;

    public string $rosterSearch = '';

    public string $rosterPlatform = '';

    /** @var list<string> */
    public array $selectedCreatorIds = [];

    public bool $showNewCreatorForm = false;

    public string $new_creator_name = '';

    public string $new_creator_language = '';

    abstract protected function rosterOwner(): Model;

    abstract protected function rosterBrand(): Brand;

    /** @return BelongsToMany<Creator, Model> */
    abstract protected function rosterRelation(): BelongsToMany;

    /** e.g. 'campaign_creator.attached' | 'seeding_campaign_creator.attached' */
    abstract protected function rosterAuditEvent(): string;

    public function openPicker(): void
    {
        $this->authorize('update', $this->rosterOwner());

        $this->rosterSearch = '';
        $this->rosterPlatform = '';
        $this->selectedCreatorIds = [];
        $this->showNewCreatorForm = false;
        $this->new_creator_name = '';
        $this->new_creator_language = '';
        $this->resetValidation();
        $this->showPicker = true;
    }

    public function closePicker(): void
    {
        $this->showPicker = false;
    }

    public function attachSelected(BrandRestrictionGuard $guard, AuditLogger $audit): void
    {
        $this->authorize('update', $this->rosterOwner());

        $ids = $this->normalizedSelectedIds();

        if ($ids === []) {
            throw ValidationException::withMessages([
                'selectedCreatorIds' => 'Pick at least one creator to add.',
            ]);
        }

        $creators = Creator::query()->whereIn('id', $ids)->get();

        // Tenant scope removes any id that is not this tenant's; a mismatch
        // means a stale or foreign id rode in — refuse the whole batch.
        if ($creators->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'selectedCreatorIds' => 'Some picked creators no longer exist.',
            ]);
        }

        $brand = $this->rosterBrand();
        $restrictedIds = $guard->restrictedCreatorIds($ids, $brand);

        $allowedIds = [];
        $skippedNames = [];

        foreach ($creators as $creator) {
            if (in_array((int) $creator->id, $restrictedIds, true)) {
                $skippedNames[] = $creator->display_name;

                continue;
            }

            try {
                // Enforcement stays single-sourced: the per-creator guard is
                // the authority, so a late-appearing restriction the bulk
                // match missed still demotes into the skipped list.
                $guard->assertNotRestricted($creator, $brand);
                $allowedIds[] = (int) $creator->id;
            } catch (BrandRestrictionViolation) {
                $skippedNames[] = $creator->display_name;
            }
        }

        if ($allowedIds !== []) {
            $result = $this->rosterRelation()->syncWithoutDetaching($allowedIds);

            foreach ($result['attached'] as $attachedId) {
                $audit->record($this->rosterAuditEvent(), $this->rosterOwner(), ['creator_id' => (int) $attachedId]);
            }
        }

        $this->rosterOwner()->refresh();
        $this->selectedCreatorIds = [];
        $this->showPicker = false;

        $skippedNotice = $this->skippedNotice($skippedNames);

        if ($allowedIds === []) {
            $this->dispatch('notify', type: 'error', message: 'No creators added.'.$skippedNotice);

            return;
        }

        $added = count($allowedIds);
        $this->dispatch(
            'notify',
            type: 'success',
            message: 'Added '.$added.' '.Str::plural('creator', $added).' to the roster.'.$skippedNotice,
        );
    }

    public function createAndAttachCreator(CreatorWriter $writer, BrandRestrictionGuard $guard, AuditLogger $audit): void
    {
        $this->authorize('create', Creator::class);
        $this->authorize('update', $this->rosterOwner());

        $validated = $this->validate([
            'new_creator_name' => ['required', 'string', 'max:255'],
            'new_creator_language' => ['nullable', 'string', 'max:10'],
        ]);

        // Through the sanctioned write path: createCreator auto-enrolls the
        // new creator into monitoring in the same transaction (the point).
        $creator = $writer->createCreator(
            $validated['new_creator_name'],
            ($validated['new_creator_language'] ?? '') !== '' ? $validated['new_creator_language'] : null,
        );

        $audit->record('creator.created', $creator, ['display_name' => $creator->display_name]);

        $result = $this->rosterRelation()->syncWithoutDetaching([$creator->id]);

        foreach ($result['attached'] as $attachedId) {
            $audit->record($this->rosterAuditEvent(), $this->rosterOwner(), ['creator_id' => (int) $attachedId]);
        }

        $this->rosterOwner()->refresh();
        $this->new_creator_name = '';
        $this->new_creator_language = '';
        $this->showNewCreatorForm = false;
        $this->resetValidation();
        $this->dispatch('notify', type: 'success', message: 'Creator created and added to the roster.');
    }

    /**
     * Candidates for the picker: creators not already on the roster, narrowed
     * by the search box (name or handle, mirroring CreatorsIndex) and the
     * platform filter. Capped at 51 so the blade can show 50 and hint that
     * the search should be refined.
     *
     * @return Collection<int, Creator>
     */
    protected function rosterCandidates(): Collection
    {
        $attachedIds = $this->rosterRelation()->pluck('creators.id')->all();

        return Creator::query()
            ->whereNotIn('id', $attachedIds)
            ->with('platformAccounts')
            ->when($this->rosterSearch !== '', function (Builder $query) {
                $query->where(function (Builder $query) {
                    $query->where('display_name', 'ilike', '%'.$this->rosterSearch.'%')
                        ->orWhereHas('platformAccounts', function (Builder $query) {
                            $query->where('handle', 'ilike', '%'.$this->rosterSearch.'%');
                        });
                });
            })
            ->when(Platform::tryFrom($this->rosterPlatform) !== null, function (Builder $query) {
                $query->whereHas('platformAccounts', fn (Builder $inner) => $inner
                    ->where('platform', $this->rosterPlatform));
            })
            ->orderBy('display_name')
            ->limit(51)
            ->get();
    }

    /**
     * View data the host's render() merges into the panel view.
     *
     * @return array{candidates: Collection<int, Creator>, restrictedIds: list<int>, blocklistedIds: list<int>, attached: Collection<int, Creator>}
     */
    protected function pickerViewData(BrandRestrictionGuard $guard): array
    {
        $attached = $this->rosterRelation()->orderBy('display_name')->get();

        // The candidate list only renders inside the open modal — don't run
        // its query (and the restriction batch) on every closed-panel render.
        if (! $this->showPicker) {
            return [
                'attached' => $attached,
                'candidates' => new Collection,
                'restrictedIds' => [],
                'blocklistedIds' => [],
            ];
        }

        $candidates = $this->rosterCandidates();
        $candidateIds = $candidates->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'attached' => $attached,
            'candidates' => $candidates,
            'restrictedIds' => $guard->restrictedCreatorIds($candidateIds, $this->rosterBrand()),
            'blocklistedIds' => $candidates
                ->filter(fn (Creator $creator) => $creator->relationship_status === RelationshipStatus::Blocklisted)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        ];
    }

    /** @return list<int> unique, positive ids from the checkbox selection */
    private function normalizedSelectedIds(): array
    {
        return collect($this->selectedCreatorIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The " Skipped M with brand restrictions: NameA, NameB and K more."
     * suffix — empty when nothing was skipped. Leading space so it appends
     * cleanly after the outcome sentence.
     *
     * @param  list<string>  $skippedNames
     */
    private function skippedNotice(array $skippedNames): string
    {
        if ($skippedNames === []) {
            return '';
        }

        $count = count($skippedNames);
        $shown = collect($skippedNames)->take(3)->implode(', ');
        $remaining = $count - min(3, $count);

        return ' Skipped '.$count.' with brand restrictions: '.$shown
            .($remaining > 0 ? ' and '.$remaining.' more' : '').'.';
    }
}
