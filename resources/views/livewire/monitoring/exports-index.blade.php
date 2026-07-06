<div>
    <div class="grid gap-4 lg:grid-cols-3">
        {{-- Request form — same validated filter set as the dashboards --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-800 dark:text-white/90">Request an export</h3>
            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                Reads approved rollups only. Files are private, expire after
                {{ (int) config('qds.exports.ttl_hours') }}h, and preserve tier labels,
                confidence, and the EMV model disclosure.
            </p>

            <div class="mt-4 space-y-3">
                <div>
                    <x-form.label for="export-format">Format</x-form.label>
                    <x-form.select id="export-format" wire:model="format">
                        @foreach ($formats as $f)
                            <option value="{{ $f->value }}">{{ $f->value }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.error name="format" />
                </div>
                <div>
                    <x-form.label for="export-grain">Grain</x-form.label>
                    <x-form.select id="export-grain" wire:model="grain">
                        @foreach ($grains as $g)
                            <option value="{{ $g }}">{{ ucfirst($g) }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.error name="grain" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <x-form.label for="export-from">From</x-form.label>
                        <x-form.input id="export-from" type="date" wire:model="from" />
                        <x-form.error name="from" />
                    </div>
                    <div>
                        <x-form.label for="export-to">To</x-form.label>
                        <x-form.input id="export-to" type="date" wire:model="to" />
                        <x-form.error name="to" />
                    </div>
                </div>
                <div>
                    <x-form.label for="export-brand">Brand</x-form.label>
                    <x-form.select id="export-brand" wire:model="brandId">
                        <option value="0">All brands</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.error name="brand_id" />
                </div>
                <div>
                    <x-form.label for="export-creator">Creator</x-form.label>
                    <x-form.select id="export-creator" wire:model="creatorId">
                        <option value="0">All monitored creators</option>
                        @foreach ($creators as $creator)
                            <option value="{{ $creator->id }}">{{ $creator->display_name }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.error name="creator_id" />
                </div>

                <x-ui.button wire:click="requestExport" wire:loading.attr="disabled">
                    Request export
                </x-ui.button>
                <p class="text-theme-xs text-gray-400">
                    Rollups refreshed {{ $rollupsRefreshedAt?->diffForHumans() ?? 'never' }}.
                    Personal data is excluded from exports by default.
                </p>
            </div>
        </div>

        {{-- The requester's own exports --}}
        <div class="lg:col-span-2">
            <x-table.container>
                <x-slot:header>
                    <h3 class="font-semibold text-gray-800 dark:text-white/90">Your exports</h3>
                </x-slot:header>
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                    <thead>
                        <tr>
                            <x-table.th>Requested</x-table.th>
                            <x-table.th>Report</x-table.th>
                            <x-table.th>Format</x-table.th>
                            <x-table.th>Filters</x-table.th>
                            <x-table.th>Status</x-table.th>
                            <x-table.th>Expires</x-table.th>
                            <x-table.th>&nbsp;</x-table.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($jobs as $job)
                            <tr wire:key="export-{{ $job->id }}">
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $job->created_at->format('Y-m-d H:i') }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $job->report }}</td>
                                <td class="px-5 py-3"><x-ui.badge color="light">{{ $job->format->value }}</x-ui.badge></td>
                                <td class="px-5 py-3 text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ collect($job->filters)->filter()->map(fn ($v, $k) => $k.'='.$v)->implode(' · ') ?: 'none' }}
                                </td>
                                <td class="px-5 py-3">
                                    <x-ui.badge :color="match ($job->status->value) {
                                        'COMPLETED' => 'success',
                                        'FAILED' => 'error',
                                        'EXPIRED' => 'light',
                                        default => 'warning',
                                    }">{{ $job->status->value }}</x-ui.badge>
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    {{ $job->expires_at?->diffForHumans() ?? '—' }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @if ($job->isDownloadable())
                                        <x-ui.button size="sm" variant="outline" wire:click="download({{ $job->id }})">
                                            Download
                                        </x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7"><x-states.empty title="No exports yet" /></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                <x-slot:footer>{{ $jobs->links() }}</x-slot:footer>
            </x-table.container>
        </div>
    </div>
</div>
