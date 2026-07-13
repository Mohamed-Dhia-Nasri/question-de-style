<?php

namespace App\Modules\CRM\Livewire\Creators;

use App\Modules\CRM\Models\Contact;
use App\Modules\CRM\Models\Creator;
use App\Shared\Audit\AuditLogger;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Contacts panel — full CRUD against ENT-Contact (REQ-M3-002, AC-M3-004:
 * MANUAL entry only). Delete is a hard delete — the UI's GDPR erasure
 * affordance (DP-005, AC-M3-006) — and is audit-logged.
 *
 * The auto-extraction affordance renders the literal "unavailable"
 * (DEF-002, AC-M3-005, Rule 8) in the panel view — never empty, never a
 * working auto-fill.
 */
class ContactsPanel extends Component
{
    public Creator $creator;

    // --- create/edit form state ---
    public bool $showForm = false;

    public ?int $editingContactId = null;

    public string $contact_email = '';

    public string $contact_phone = '';

    public string $contact_postal_address = '';

    public string $contact_preferred_channel = '';

    // --- delete confirmation state ---
    public ?int $confirmingDeleteId = null;

    public function mount(Creator $creator): void
    {
        $this->authorize('view', $creator);

        $this->creator = $creator;
    }

    public function add(): void
    {
        $this->authorize('create', Contact::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $contactId): void
    {
        $contact = $this->creator->contacts()->findOrFail($contactId);

        $this->authorize('update', $contact);

        $this->resetForm();
        $this->editingContactId = $contact->id;
        $this->contact_email = $contact->email ?? '';
        $this->contact_phone = $contact->phone ?? '';
        $this->contact_postal_address = $contact->postal_address ?? '';
        $this->contact_preferred_channel = $contact->preferred_channel ?? '';
        $this->showForm = true;
    }

    public function save(): void
    {
        $editing = $this->editingContactId !== null;
        $contact = $editing ? $this->creator->contacts()->findOrFail($this->editingContactId) : null;

        $this->authorize($editing ? 'update' : 'create', $contact ?? Contact::class);

        // All detail fields are optional per the canonical ENT-Contact shape.
        $validated = $this->validate([
            'contact_email' => ['nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:255'],
            'contact_postal_address' => ['nullable', 'string', 'max:2000'],
            'contact_preferred_channel' => ['nullable', 'string', 'max:255'],
        ]);

        $attributes = [
            'email' => ($validated['contact_email'] ?? '') !== '' ? $validated['contact_email'] : null,
            'phone' => ($validated['contact_phone'] ?? '') !== '' ? $validated['contact_phone'] : null,
            'postal_address' => ($validated['contact_postal_address'] ?? '') !== '' ? $validated['contact_postal_address'] : null,
            'preferred_channel' => ($validated['contact_preferred_channel'] ?? '') !== '' ? $validated['contact_preferred_channel'] : null,
        ];

        if ($editing) {
            $contact->update($attributes);
        } else {
            $this->creator->contacts()->create($attributes);
        }

        $this->creator->refresh();
        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('notify', type: 'success', message: $editing ? 'Contact updated.' : 'Contact added.');
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    // --- delete (GDPR erasure affordance, DP-005) -----------------------------

    public function confirmDelete(int $contactId): void
    {
        $contact = $this->creator->contacts()->findOrFail($contactId);

        $this->authorize('delete', $contact);

        $this->confirmingDeleteId = $contactId;
    }

    public function delete(AuditLogger $audit): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $contact = $this->creator->contacts()->findOrFail($this->confirmingDeleteId);

        $this->authorize('delete', $contact);

        // Identifiers only in the audit context — never the personal data
        // being erased (AuditLogger rule + DP-005).
        $audit->record('contact.deleted', $contact);

        $contact->delete();

        $this->creator->refresh();
        $this->confirmingDeleteId = null;
        $this->dispatch('notify', type: 'success', message: 'Contact deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    // -------------------------------------------------------------------------

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingContactId = null;
        $this->contact_email = '';
        $this->contact_phone = '';
        $this->contact_postal_address = '';
        $this->contact_preferred_channel = '';
    }

    public function render(): View
    {
        return view('livewire.crm.creator-contacts', [
            'contacts' => $this->creator->contacts()->orderBy('id')->get(),
        ]);
    }
}
