<?php

namespace App\Modules\CRM\Livewire\Creators;

use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Services\CreatorWriter;
use App\Shared\Enums\RelationshipStatus;
use App\Shared\Livewire\Concerns\RunsCreatorMonitoringNow;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Creator profile — identity card (spec §2.4): the creator's own fields,
 * including the editable ENUM-RelationshipStatus stage (REQ-M3-004). Display-
 * first (Stage B): the card renders read-only text until `edit()` opens the
 * form; `save()` returns it to read-only on success. The
 * account/contact/preference/log panels are sibling Livewire components on
 * the same page. There is deliberately NO merge or reassign control here
 * (ADR-0014). Creator writes route through CreatorWriter.
 *
 * Also hosts the operator's "run monitoring now" lever
 * (RunsCreatorMonitoringNow) — a monitoring action that happens to live on
 * a CRM page, gated on monitoring.manage, not crm.manage.
 */
class CreatorProfile extends Component
{
    use RunsCreatorMonitoringNow;

    public Creator $creator;

    public bool $editing = false;

    public string $display_name = '';

    public string $primary_language = '';

    public string $relationship_status = '';

    public function mount(Creator $creator): void
    {
        $this->authorize('view', $creator);

        $this->creator = $creator;
        $this->fillForm();
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'display_name' => 'display name',
            'primary_language' => 'primary language',
            'relationship_status' => 'relationship status',
        ];
    }

    public function save(CreatorWriter $writer): void
    {
        $this->authorize('update', $this->creator);

        $validated = $this->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'primary_language' => ['nullable', 'string', 'max:10'],
            'relationship_status' => ['nullable', Rule::in(array_column(RelationshipStatus::cases(), 'value'))],
        ]);

        $writer->updateCreator(
            $this->creator,
            $validated['display_name'],
            ($validated['primary_language'] ?? '') !== '' ? $validated['primary_language'] : null,
            ($validated['relationship_status'] ?? '') !== ''
                ? RelationshipStatus::from($validated['relationship_status'])
                : null,
        );

        $this->creator->refresh();
        $this->fillForm();
        $this->editing = false;
        $this->dispatch('notify', type: 'success', message: 'Creator updated.');
    }

    public function edit(): void
    {
        $this->authorize('update', $this->creator);

        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->resetValidation();
        $this->fillForm();
        $this->editing = false;
    }

    protected function fillForm(): void
    {
        $this->display_name = $this->creator->display_name;
        $this->primary_language = $this->creator->primary_language ?? '';
        $this->relationship_status = $this->creator->relationship_status->value ?? '';
    }

    public function render(): View
    {
        return view('livewire.crm.creator-profile', [
            'statuses' => RelationshipStatus::cases(),
            'statusDescriptions' => collect(RelationshipStatus::cases())
                ->mapWithKeys(fn ($s) => [$s->value => $s->description()])
                ->all(),
        ]);
    }
}
