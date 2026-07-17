<div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4 dark:border-gray-800">
        <div>
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Platform accounts</h3>
            <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                The accounts your team has confirmed belong to this person — added and removed by
                hand.
            </p>
        </div>
        {{-- Deliberately NO auto-detect and NO merge control here: ADR-0014
             removes the automatic path as an absent FEATURE (not an
             unavailable data field), so no "unavailable" surface exists. --}}
        @can('create', \App\Modules\CRM\Models\PlatformAccount::class)
            <x-ui.button size="sm" wire:click="add">Add account</x-ui.button>
        @endcan
    </div>

    @if ($accounts->isEmpty())
        <x-states.empty title="No platform accounts yet">
            Link this creator's Instagram, TikTok, or YouTube account — monitoring starts from
            here.
            <x-slot:action>
                @can('create', \App\Modules\CRM\Models\PlatformAccount::class)
                    <x-ui.button size="sm" wire:click="add">Add account</x-ui.button>
                @endcan
            </x-slot:action>
        </x-states.empty>
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px]">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <x-table.th>Platform</x-table.th>
                        <x-table.th>Handle</x-table.th>
                        <x-table.th>Bio</x-table.th>
                        <x-table.th>Links</x-table.th>
                        <x-table.th>Followers</x-table.th>
                        <x-table.th>Data origin</x-table.th>
                        <x-table.th><span class="sr-only">Actions</span></x-table.th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($accounts as $account)
                        <tr wire:key="account-{{ $account->id }}">
                            <td class="px-5 py-4">
                                <x-ui.badge color="light">{{ $account->platform->label() }}</x-ui.badge>
                            </td>
                            <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">
                                {{ $account->handle }}
                            </td>
                            <td class="max-w-xs truncate px-5 py-4 text-sm text-gray-500 dark:text-gray-400"
                                title="{{ $account->bio }}">
                                {{ $account->bio ?: '—' }}
                            </td>
                            <td class="px-5 py-4">
                                @if (empty($account->external_links))
                                    <span class="text-sm text-gray-400">&mdash;</span>
                                @else
                                    <div class="flex flex-col gap-0.5">
                                        @foreach ($account->external_links as $link)
                                            <a href="{{ $link }}" target="_blank" rel="noopener noreferrer"
                                                class="max-w-[14rem] truncate text-theme-xs text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                                {{ $link }}
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                @if ($account->follower_count)
                                    <span class="text-sm text-gray-800 dark:text-white/90">
                                        {{ number_format($account->follower_count->amount, 0, ',', '.') }}
                                    </span>
                                    {{-- DP-001: the tier travels with the number. --}}
                                    <x-metric.tier-badge :tier="$account->follower_count->tier" />
                                @else
                                    <span class="text-sm text-gray-400">&mdash;</span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                @if ($account->provenance->source === \App\Platform\Ingestion\SourceRegistry::AGENCY_MANUAL_ENTRY)
                                    <x-ui.badge color="info" title="Entered by hand by agency staff.">Manual entry</x-ui.badge>
                                @else
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400"
                                        title="Fetched {{ $account->provenance->fetchedAt->format('d.m.Y H:i') }}">
                                        {{ $account->provenance->source }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    @can('update', $account)
                                        <button type="button" wire:click="edit({{ $account->id }})"
                                            class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                            Edit
                                        </button>
                                    @endcan
                                    @can('delete', $account)
                                        <button type="button" wire:click="confirmRemove({{ $account->id }})"
                                            class="text-sm font-medium text-error-500 hover:text-error-600">
                                            Remove
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

    {{-- Add / edit modal --}}
    @if ($showForm)
        <x-ui.modal :title="$editingAccountId ? 'Edit platform account' : 'Add platform account'" close-action="cancelForm">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <x-form.label for="account_platform" required>Platform</x-form.label>
                    <x-form.select id="account_platform" wire:model="account_platform"
                        :error="$errors->has('account_platform')">
                        <option value="">Select a platform…</option>
                        @foreach ($platforms as $platformOption)
                            <option value="{{ $platformOption->value }}">{{ $platformOption->label() }}</option>
                        @endforeach
                    </x-form.select>
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                        A creator holds at most one account per platform.
                    </p>
                    <x-form.error for="account_platform" />
                </div>

                <div>
                    <x-form.label for="account_handle" required>Handle</x-form.label>
                    <x-form.input id="account_handle" wire:model="account_handle"
                        :error="$errors->has('account_handle')" placeholder="@handle or channel id" />
                    <x-form.error for="account_handle" />
                </div>

                <div>
                    <x-form.label for="account_bio">Bio</x-form.label>
                    <x-form.textarea id="account_bio" wire:model="account_bio" rows="3"
                        :error="$errors->has('account_bio')" />
                    <x-form.error for="account_bio" />
                </div>

                <div>
                    <x-form.label for="account_links">External links</x-form.label>
                    <x-form.textarea id="account_links" wire:model="account_links" rows="3"
                        :error="$errors->has('account_links')" placeholder="https://… (one per line)" />
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                        Public profile links only — emails and phone numbers belong in the
                        Contacts panel.
                    </p>
                    <x-form.error for="account_links" />
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelForm" wire:loading.attr="disabled">
                    Cancel
                </x-ui.button>
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editingAccountId ? 'Save changes' : 'Add account' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Remove confirmation --}}
    @if ($confirmingRemoveId !== null)
        <x-ui.confirm-modal title="Remove platform account?" confirm-action="remove" cancel-action="cancelRemove"
            confirm-label="Remove account">
            Removing an account stops it from counting toward this creator. If it belongs to a
            different creator, remove it here and add it there.
        </x-ui.confirm-modal>
    @endif
</div>
