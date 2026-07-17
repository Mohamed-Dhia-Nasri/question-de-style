<div>
    @if ($open)
        <x-ui.modal title="Import creators from CSV" close-action="close" max-width="2xl">
            @if ($result !== null)
                {{-- Outcome --}}
                <div class="space-y-4">
                    <p class="text-sm text-gray-800 dark:text-white/90">
                        Imported {{ $result['created'] }} {{ \Illuminate\Support\Str::plural('creator', $result['created']) }}.
                    </p>

                    @if (! empty($result['skipped']))
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                            <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                These rows were skipped:
                            </p>
                            <ul class="mt-2 space-y-1 text-theme-sm text-gray-500 dark:text-gray-400">
                                @foreach ($result['skipped'] as $skippedRow)
                                    <li>
                                        {{ $skippedRow['name'] !== '' ? $skippedRow['name'] : 'Row '.$skippedRow['line'] }}
                                        &mdash; {{ $skippedRow['reason'] }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @elseif (! empty($rows))
                {{-- Preview --}}
                <div class="space-y-4">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[600px]">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-800">
                                    <x-table.th>Name</x-table.th>
                                    <x-table.th>Language</x-table.th>
                                    <x-table.th>Handles</x-table.th>
                                    <x-table.th>Status</x-table.th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($rows as $row)
                                    <tr wire:key="csv-row-{{ $row['line'] }}">
                                        <td class="px-4 py-3 text-sm text-gray-800 dark:text-white/90">
                                            {{ $row['name'] !== '' ? $row['name'] : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                            {{ $row['language'] !== '' ? $row['language'] : '—' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            @if (empty($row['handles']))
                                                <span class="text-sm text-gray-400">—</span>
                                            @else
                                                <div class="flex flex-wrap gap-1.5">
                                                    @foreach ($row['handles'] as $platformValue => $handle)
                                                        <x-ui.badge color="light">
                                                            {{ \App\Shared\Enums\Platform::from($platformValue)->label() }} · {{ '@'.$handle }}
                                                        </x-ui.badge>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            @if ($row['verdict'] === 'ready')
                                                <span class="font-medium text-success-600 dark:text-success-500">Ready</span>
                                            @else
                                                <span class="text-warning-600 dark:text-orange-400">{{ $row['reason'] }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $readyCount }} of {{ count($rows) }} rows will be imported.
                    </p>
                </div>
            @else
                {{-- Upload --}}
                <div class="space-y-4">
                    <div>
                        <x-form.label for="csv-upload" required>CSV file</x-form.label>
                        <x-form.input id="csv-upload" wire:model="upload" type="file" :error="$errors->has('upload')" />
                        <div wire:loading wire:target="upload" class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            Reading the file…
                        </div>
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            Columns: name (required), language, instagram, tiktok, youtube.
                            One creator per row — handles without the @.
                        </p>
                        <x-form.error for="upload" />
                    </div>

                    <div class="rounded-xl border border-gray-200 p-4 text-theme-sm text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        Every imported creator is monitored automatically.
                    </div>
                </div>
            @endif

            <x-slot:footer>
                @if ($result !== null)
                    <x-ui.button wire:click="close">Done</x-ui.button>
                @elseif (! empty($rows))
                    <x-ui.button variant="outline" wire:click="chooseAnotherFile" wire:loading.attr="disabled">
                        Choose another file
                    </x-ui.button>
                    <x-ui.button wire:click="import" wire:target="import" wire:loading.attr="disabled"
                        :disabled="$readyCount === 0">
                        <span wire:loading.remove wire:target="import">Import {{ $readyCount }} creators</span>
                        <span wire:loading wire:target="import">Importing…</span>
                    </x-ui.button>
                @else
                    <x-ui.button variant="outline" wire:click="close" wire:loading.attr="disabled">Cancel</x-ui.button>
                @endif
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
