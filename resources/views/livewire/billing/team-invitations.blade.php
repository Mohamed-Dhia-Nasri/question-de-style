<div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Team invitations</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Team seats: <span class="font-medium text-gray-800 dark:text-white/90">{{ $seatsUsed }} / {{ $seatLimit ?? '∞' }} used</span>
        </p>
    </div>

    @if ($overLimit)
        <x-ui.alert variant="warning" class="mb-4">
            The team is over its seat limit — deactivate members or upgrade the plan before
            inviting or activating anyone.
        </x-ui.alert>
    @endif

    {{-- Invite form --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
        <div>
            <x-form.label for="invite-email" required>Email</x-form.label>
            <x-form.input id="invite-email" type="email" wire:model="email" placeholder="name@agency.de"
                :error="$errors->has('email')" />
            <x-form.error for="email" />
        </div>
        <div>
            <x-form.label for="invite-role" required>Role</x-form.label>
            <x-form.select id="invite-role" wire:model="role" :error="$errors->has('role')">
                <option value="">Select a role…</option>
                @foreach ($roles as $roleOption)
                    <option value="{{ $roleOption->value }}">{{ $roleOption->label() }}</option>
                @endforeach
            </x-form.select>
            <x-form.error for="role" />
        </div>
        <div>
            <x-ui.button wire:click="invite" wire:loading.attr="disabled" wire:target="invite">
                <span wire:loading.remove wire:target="invite">Send invitation</span>
                <span wire:loading wire:target="invite">Sending…</span>
            </x-ui.button>
        </div>
    </div>

    {{-- Pending invitations --}}
    @if ($invitations->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">No pending invitations.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <th class="px-5 py-3 text-theme-xs font-medium uppercase text-gray-400">Email</th>
                        <th class="px-5 py-3 text-theme-xs font-medium uppercase text-gray-400">Role</th>
                        <th class="px-5 py-3 text-theme-xs font-medium uppercase text-gray-400">Invited by</th>
                        <th class="px-5 py-3 text-theme-xs font-medium uppercase text-gray-400">Expires</th>
                        <th class="px-5 py-3 text-theme-xs font-medium uppercase text-gray-400">Status</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invitations as $invitation)
                        <tr wire:key="invitation-{{ $invitation->id }}" class="border-b border-gray-100 dark:border-gray-800">
                            <td class="px-5 py-3 text-sm text-gray-800 dark:text-white/90">{{ $invitation->email }}</td>
                            <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $invitation->role->label() }}</td>
                            <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $invitation->invitedBy?->display_name ?? '—' }}</td>
                            <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $invitation->expires_at->toFormattedDateString() }}</td>
                            <td class="px-5 py-3 text-sm">
                                @if ($invitation->isPending())
                                    <x-ui.badge color="warning">Pending</x-ui.badge>
                                @else
                                    <x-ui.badge color="light">{{ $invitation->statusLabel() }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right">
                                <x-ui.button variant="outline" size="sm" wire:click="revoke({{ $invitation->id }})"
                                    wire:loading.attr="disabled">
                                    Revoke
                                </x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
