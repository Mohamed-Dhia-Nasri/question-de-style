<div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4 dark:border-gray-800">
        <div>
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Contacts</h3>
            <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                Manual entry only (REQ-M3-002). Deleting a contact is the GDPR erasure path (DP-005).
            </p>
        </div>
        <div class="flex items-center gap-3">
            {{-- DEF-002 / AC-M3-005: the auto-extraction affordance renders the
                 literal "unavailable" — never empty, never a working auto-fill. --}}
            <span class="flex items-center gap-2 text-theme-xs text-gray-500 dark:text-gray-400">
                Auto-extract email/phone:
                <x-states.unavailable reason="Contact auto-extraction is deferred (DEF-002, ADR-0005) — enter contact details manually." />
            </span>
            @can('create', \App\Modules\CRM\Models\Contact::class)
                <x-ui.button size="sm" wire:click="add">Add contact</x-ui.button>
            @endcan
        </div>
    </div>

    @if ($contacts->isEmpty())
        <x-states.empty title="No contacts yet">
            Contact details are entered manually — auto-extraction is not part of v1 (DEF-002).
        </x-states.empty>
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[800px]">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <x-table.th>Email</x-table.th>
                        <x-table.th>Phone</x-table.th>
                        <x-table.th>Postal address</x-table.th>
                        <x-table.th>Preferred channel</x-table.th>
                        <x-table.th><span class="sr-only">Actions</span></x-table.th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($contacts as $contact)
                        <tr wire:key="contact-{{ $contact->id }}">
                            <td class="px-5 py-4 text-sm text-gray-800 dark:text-white/90">{{ $contact->email ?: '—' }}</td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $contact->phone ?: '—' }}</td>
                            <td class="max-w-xs truncate px-5 py-4 text-sm text-gray-500 dark:text-gray-400"
                                title="{{ $contact->postal_address }}">
                                {{ $contact->postal_address ?: '—' }}
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $contact->preferred_channel ?: '—' }}</td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    @can('update', $contact)
                                        <button type="button" wire:click="edit({{ $contact->id }})"
                                            class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                            Edit
                                        </button>
                                    @endcan
                                    @can('delete', $contact)
                                        <button type="button" wire:click="confirmDelete({{ $contact->id }})"
                                            class="text-sm font-medium text-error-500 hover:text-error-600">
                                            Delete
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Create / edit modal --}}
    @if ($showForm)
        <x-ui.modal :title="$editingContactId ? 'Edit contact' : 'Add contact'" close-action="cancelForm">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <x-form.label for="contact_email">Email</x-form.label>
                    <x-form.input id="contact_email" wire:model="contact_email" type="email"
                        :error="$errors->has('contact_email')" placeholder="name@example.de" />
                    <x-form.error for="contact_email" />
                </div>

                <div>
                    <x-form.label for="contact_phone">Phone</x-form.label>
                    <x-form.input id="contact_phone" wire:model="contact_phone"
                        :error="$errors->has('contact_phone')" />
                    <x-form.error for="contact_phone" />
                </div>

                <div>
                    <x-form.label for="contact_postal_address">Postal address</x-form.label>
                    <x-form.textarea id="contact_postal_address" wire:model="contact_postal_address" rows="3"
                        :error="$errors->has('contact_postal_address')" placeholder="For shipments" />
                    <x-form.error for="contact_postal_address" />
                </div>

                <div>
                    <x-form.label for="contact_preferred_channel">Preferred channel</x-form.label>
                    <x-form.input id="contact_preferred_channel" wire:model="contact_preferred_channel"
                        :error="$errors->has('contact_preferred_channel')" placeholder="e.g. email" />
                    <x-form.error for="contact_preferred_channel" />
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelForm" wire:loading.attr="disabled">
                    Cancel
                </x-ui.button>
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editingContactId ? 'Save changes' : 'Add contact' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Delete confirmation (GDPR erase) --}}
    @if ($confirmingDeleteId !== null)
        <x-ui.confirm-modal title="Delete contact?" confirm-action="delete" cancel-action="cancelDelete"
            confirm-label="Delete contact">
            This permanently and irreversibly erases the contact's personal data (GDPR erasure,
            DP-005). Only the deletion event — no personal data — is recorded in the audit log.
        </x-ui.confirm-modal>
    @endif
</div>
