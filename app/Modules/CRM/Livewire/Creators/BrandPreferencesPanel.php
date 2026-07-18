<?php

namespace App\Modules\CRM\Livewire\Creators;

use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Services\BrandRestrictionGuard;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Brand preferences panel — CRUD against ENT-BrandPreference (REQ-M3-003).
 * Preferred/restricted brands are LISTS OF STRING per the canonical shape
 * (plain names, never brand FK pickers). Enforcing restrictions as hard
 * filters on campaign/seeding joins is Step-3 logic — this panel only
 * records them.
 */
class BrandPreferencesPanel extends Component
{
    public Creator $creator;

    // --- create/edit form state ---
    public bool $showForm = false;

    public ?int $editingPreferenceId = null;

    /** One brand name per line. */
    public string $preference_preferred = '';

    /** One brand name per line. */
    public string $preference_restricted = '';

    public string $preference_notes = '';

    // --- delete confirmation state ---
    public ?int $confirmingDeleteId = null;

    public function mount(Creator $creator): void
    {
        $this->authorize('view', $creator);

        $this->creator = $creator;
    }

    public function add(): void
    {
        $this->authorize('create', BrandPreference::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $preferenceId): void
    {
        $preference = $this->creator->brandPreferences()->findOrFail($preferenceId);

        $this->authorize('update', $preference);

        $this->resetForm();
        $this->editingPreferenceId = $preference->id;
        $this->preference_preferred = implode("\n", $preference->preferred_brands ?? []);
        $this->preference_restricted = implode("\n", $preference->restricted_brands ?? []);
        $this->preference_notes = $preference->notes ?? '';
        $this->showForm = true;
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'preference_preferred' => 'preferred brands',
            'preference_restricted' => 'restricted brands',
            'preference_notes' => 'notes',
        ];
    }

    public function save(BrandRestrictionGuard $guard): void
    {
        $editing = $this->editingPreferenceId !== null;
        $preference = $editing ? $this->creator->brandPreferences()->findOrFail($this->editingPreferenceId) : null;

        $this->authorize($editing ? 'update' : 'create', $preference ?? BrandPreference::class);

        $validated = $this->validate([
            'preference_preferred' => ['nullable', 'string', 'max:5000'],
            'preference_restricted' => ['nullable', 'string', 'max:5000'],
            'preference_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // Captured BEFORE persisting — item 5c needs the pre-save state to
        // tell which restricted names are newly added, never auto-detaching
        // any roster the creator already sits on.
        $oldRestricted = $preference->restricted_brands ?? [];
        $newRestricted = $this->parseBrandList($validated['preference_restricted'] ?? '');

        $attributes = [
            'preferred_brands' => $this->parseBrandList($validated['preference_preferred'] ?? ''),
            'restricted_brands' => $newRestricted,
            'notes' => ($validated['preference_notes'] ?? '') !== '' ? $validated['preference_notes'] : null,
        ];

        if ($editing) {
            $preference->update($attributes);
        } else {
            $this->creator->brandPreferences()->create($attributes);
        }

        $this->creator->refresh();
        $this->showForm = false;
        $this->resetForm();

        $addedNames = $this->newlyAddedFoldedNames($newRestricted, $oldRestricted);
        $matchedRosterNames = $addedNames === [] ? [] : $this->rostersMatchingAddedNames($guard, $addedNames);

        if ($matchedRosterNames !== []) {
            $this->dispatch('notify', type: 'error', message: $this->rosterWarningMessage($matchedRosterNames));
        } else {
            $this->dispatch('notify', type: 'success', message: $editing ? 'Brand preference updated.' : 'Brand preference added.');
        }
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    // --- delete ----------------------------------------------------------------

    public function confirmDelete(int $preferenceId): void
    {
        $preference = $this->creator->brandPreferences()->findOrFail($preferenceId);

        $this->authorize('delete', $preference);

        $this->confirmingDeleteId = $preferenceId;
    }

    public function delete(): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $preference = $this->creator->brandPreferences()->findOrFail($this->confirmingDeleteId);

        $this->authorize('delete', $preference);

        $preference->delete();

        $this->creator->refresh();
        $this->confirmingDeleteId = null;
        $this->dispatch('notify', type: 'success', message: 'Brand preference deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    // -------------------------------------------------------------------------

    /** @return list<string> plain brand names (canonical: list of string) */
    protected function parseBrandList(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));
    }

    /**
     * Item 5c: which restricted names are NEW in this save, folded the
     * same way as BrandRestrictionGuard (mb_strtolower(trim())) so the
     * roster re-check cannot diverge from the enforcement paths.
     *
     * @param  list<string>  $new
     * @param  list<string>  $old
     * @return list<string> folded, de-duplicated, newly-added names
     */
    protected function newlyAddedFoldedNames(array $new, array $old): array
    {
        $fold = fn (string $name): string => mb_strtolower(trim($name));

        $oldFolded = array_map($fold, $old);

        return collect($new)
            ->map($fold)
            ->filter()
            ->unique()
            ->reject(fn (string $name) => in_array($name, $oldFolded, true))
            ->values()
            ->all();
    }

    /**
     * Item 5c: which of the creator's existing rosters (campaigns/seeding
     * runs) are for a brand that now matches a newly-added restriction —
     * by name OR alias. Read-only: never detaches anything, only reports.
     *
     * @param  list<string>  $addedFoldedNames
     * @return list<string> roster names, in first-seen order
     */
    protected function rostersMatchingAddedNames(BrandRestrictionGuard $guard, array $addedFoldedNames): array
    {
        $matches = [];

        foreach ($this->creator->campaigns()->with('brand')->get() as $campaign) {
            if ($guard->matchesAnyNeedle($campaign->brand, $addedFoldedNames)) {
                $matches[] = $campaign->name;
            }
        }

        foreach ($this->creator->seedingCampaigns()->with('brand')->get() as $seedingCampaign) {
            if ($guard->matchesAnyNeedle($seedingCampaign->brand, $addedFoldedNames)) {
                $matches[] = $seedingCampaign->name;
            }
        }

        return $matches;
    }

    /**
     * @param  list<string>  $rosterNames
     */
    protected function rosterWarningMessage(array $rosterNames): string
    {
        $shown = array_slice($rosterNames, 0, 3);
        $remaining = count($rosterNames) - count($shown);

        $list = implode(', ', $shown);
        if ($remaining > 0) {
            $list .= " and {$remaining} more";
        }

        return sprintf(
            'Heads up: this creator is already on %d roster(s) for a brand you just restricted: %s.',
            count($rosterNames),
            $list
        );
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingPreferenceId = null;
        $this->preference_preferred = '';
        $this->preference_restricted = '';
        $this->preference_notes = '';
    }

    public function render(): View
    {
        return view('livewire.crm.creator-brand-preferences', [
            'preferences' => $this->creator->brandPreferences()->orderBy('id')->get(),
        ]);
    }
}
