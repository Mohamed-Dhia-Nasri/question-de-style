<?php

namespace App\Modules\CRM\Livewire\Creators;

use App\Modules\CRM\Models\BrandPreference;
use App\Modules\CRM\Models\Creator;
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

    public function save(): void
    {
        $editing = $this->editingPreferenceId !== null;
        $preference = $editing ? $this->creator->brandPreferences()->findOrFail($this->editingPreferenceId) : null;

        $this->authorize($editing ? 'update' : 'create', $preference ?? BrandPreference::class);

        $validated = $this->validate([
            'preference_preferred' => ['nullable', 'string', 'max:5000'],
            'preference_restricted' => ['nullable', 'string', 'max:5000'],
            'preference_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $attributes = [
            'preferred_brands' => $this->parseBrandList($validated['preference_preferred'] ?? ''),
            'restricted_brands' => $this->parseBrandList($validated['preference_restricted'] ?? ''),
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
        $this->dispatch('notify', type: 'success', message: $editing ? 'Brand preference updated.' : 'Brand preference added.');
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
