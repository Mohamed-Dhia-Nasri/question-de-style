<div>
    {{-- Server-side filters (validated in the component; MonitoringOverview idiom) --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <x-form.label for="results-grain">Grain</x-form.label>
            <x-form.select id="results-grain" wire:model.live="grain">
                @foreach ($grains as $g)
                    <option value="{{ $g }}">{{ ucfirst($g) }}</option>
                @endforeach
            </x-form.select>
        </div>
        <div>
            <x-form.label for="results-from">From</x-form.label>
            <x-form.input id="results-from" type="date" wire:model.live="from" />
        </div>
        <div>
            <x-form.label for="results-to">To</x-form.label>
            <x-form.input id="results-to" type="date" wire:model.live="to" />
        </div>
        <div>
            <x-form.label for="results-brand">Brand</x-form.label>
            <x-form.select id="results-brand" wire:model.live="brandId">
                <option value="0">All brands</option>
                @foreach ($brands as $brand)
                    <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                @endforeach
            </x-form.select>
        </div>
        <div>
            <x-form.label for="results-product">Product</x-form.label>
            <x-form.select id="results-product" wire:model.live="productId">
                <option value="0">All products</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}">{{ $product->name }}</option>
                @endforeach
            </x-form.select>
        </div>
        <div>
            <x-form.label for="results-platform">Platform (slice)</x-form.label>
            <x-form.select id="results-platform" wire:model.live="platform">
                <option value="">All platforms</option>
                @foreach ($platforms as $p)
                    <option value="{{ $p->value }}">{{ $p->value }}</option>
                @endforeach
            </x-form.select>
        </div>
        <div>
            <x-form.label for="results-content-type">Content type (slice)</x-form.label>
            <x-form.select id="results-content-type" wire:model.live="contentType">
                <option value="">All content types</option>
                @foreach ($contentTypes as $type)
                    <option value="{{ $type->value }}">{{ $type->value }}</option>
                @endforeach
            </x-form.select>
        </div>
        <div>
            <x-form.label for="results-country">Creator country (slice)</x-form.label>
            <x-form.select id="results-country" wire:model.live="country">
                <option value="">All countries</option>
                @foreach ($countries as $c)
                    <option value="{{ $c->value }}">{{ $c->label() }}</option>
                @endforeach
            </x-form.select>
            <p class="mt-1 text-theme-xs text-gray-400 dark:text-gray-500">
                Geography belongs to the POSTING CREATOR (ADR-0018), never to a brand or product; rows without creator geography render as unavailable.
            </p>
        </div>
        <div>
            <x-form.label for="results-city">Creator city (slice)</x-form.label>
            <x-form.select id="results-city" wire:model.live="city">
                <option value="">All cities</option>
                @foreach ($cities as $cityOption)
                    <option value="{{ $cityOption }}">{{ $cityOption }}</option>
                @endforeach
            </x-form.select>
            <p class="mt-1 text-theme-xs text-gray-400 dark:text-gray-500">
                Cities come from assigned creator geography.
            </p>
        </div>
    </div>

    {{-- AC-M3-019: one cross-influencer total per product — rollups only (ADR-0010) --}}

    {{-- Export the CURRENT view (REQ-M1-012 parity): same filters, chosen
         format; the artifact builds asynchronously on the Exports page. --}}
    @can('create', \App\Platform\Export\Models\ExportJob::class)
        <div class="mb-6 flex flex-wrap items-end gap-3 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="w-full sm:w-40">
                <x-form.label for="results-export-format">Export format</x-form.label>
                <x-form.select id="results-export-format" wire:model="exportFormat">
                    <option value="CSV">CSV</option>
                    <option value="EXCEL">Excel</option>
                    <option value="PDF">PDF</option>
                </x-form.select>
            </div>
            <x-ui.button wire:click="export" wire:loading.attr="disabled" wire:target="export">
                <span wire:loading.remove wire:target="export">Export current view</span>
                <span wire:loading wire:target="export">Queuing…</span>
            </x-ui.button>
            <p class="text-theme-xs text-gray-400 dark:text-gray-500">
                Exports carry exactly the filters above — clear them all for a full
                everything export. Download from the
                <a href="{{ route('monitoring.exports.index') }}" class="text-brand-500 hover:underline">Exports page</a>.
            </p>
        </div>
    @endcan

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-800">
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Product totals</h3>
            <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                @if ($sliceActive)
                    Slice active — content-side measures only. Shipment-level columns (shipments,
                    posted, post rate) are not shown: shipments carry no platform, content-type,
                    country, or city dimension; clear the slice to see them. "Creators posted"
                    counts creators with matched content inside the slice.
                @else
                    Cross-influencer totals per product (REQ-M3-013) — post rate is DERIVED,
                    recomputed at the rollup grain, never summed.
                @endif
            </p>
        </div>

        @if ($rows->isEmpty())
            <x-states.empty title="No seeding results in the rollups yet">
                Results appear after shipments are recorded, content is matched, and the
                analytics rollups refresh.
            </x-states.empty>
        @elseif ($sliceActive)
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1000px]">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-800">
                            <x-table.th>Product</x-table.th>
                            <x-table.th>Period</x-table.th>
                            <x-table.th>Platform</x-table.th>
                            <x-table.th>Content type</x-table.th>
                            <x-table.th>Creator country</x-table.th>
                            <x-table.th>Creator city</x-table.th>
                            <x-table.th>Creators posted</x-table.th>
                            <x-table.th>Content</x-table.th>
                            <x-table.th>Views</x-table.th>
                            <x-table.th>Engagement</x-table.th>
                            <x-table.th>Est. reach</x-table.th>
                            <x-table.th>EMV</x-table.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($rows as $row)
                            <tr wire:key="slice-{{ $row->bucket_start }}-{{ $row->product_id }}-{{ $row->platform }}-{{ $row->content_type }}-{{ $row->country ?? 'na' }}-{{ $row->city ?? 'na' }}">
                                <td class="px-5 py-3 text-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ $productNames[$row->product_id] ?? '#'.$row->product_id }}
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $row->bucket_start }}</td>
                                <td class="px-5 py-3"><x-ui.badge color="light" size="sm">{{ $row->platform }}</x-ui.badge></td>
                                <td class="px-5 py-3"><x-ui.badge color="light" size="sm">{{ $row->content_type }}</x-ui.badge></td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->country !== null)
                                        {{ \App\Shared\Enums\Country::labelFor($row->country) }}
                                    @else
                                        <x-states.unavailable reason="This creator has no assigned geography — country is never guessed." />
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->city !== null)
                                        {{ $row->city }}
                                    @else
                                        <x-states.unavailable reason="This creator has no assigned city — location is never guessed." />
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ number_format((int) $row->creators_reached) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ number_format((int) $row->content_count) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->total_views !== null)
                                        {{ number_format((float) $row->total_views) }} <x-metric.tier-badge tier="PUBLIC" />
                                    @else
                                        <x-states.unavailable reason="No observed views in this slice — never shown as zero." />
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->total_engagement !== null)
                                        {{ number_format((float) $row->total_engagement) }} <x-metric.tier-badge tier="DERIVED" />
                                    @else
                                        <x-states.unavailable reason="No observed engagement in this slice — never shown as zero." />
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->total_estimated_reach !== null)
                                        {{ number_format((float) $row->total_estimated_reach) }} <x-metric.tier-badge tier="ESTIMATED" />
                                    @else
                                        <x-states.unavailable reason="No estimated reach for this slice yet (REQ-M1-006)." />
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->total_emv !== null)
                                        {{ number_format((float) $row->total_emv, 2) }} <x-metric.tier-badge tier="ESTIMATED" />
                                    @else
                                        <x-states.unavailable reason="EMV requires an active configuration and calculated results (REQ-M1-011)." />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1100px]">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-800">
                            <x-table.th>Product</x-table.th>
                            <x-table.th>Period</x-table.th>
                            <x-table.th>Shipments</x-table.th>
                            <x-table.th>Posted</x-table.th>
                            <x-table.th>Post rate</x-table.th>
                            <x-table.th>Creators reached</x-table.th>
                            <x-table.th>Content</x-table.th>
                            <x-table.th>Views</x-table.th>
                            <x-table.th>Engagement</x-table.th>
                            <x-table.th>Est. reach</x-table.th>
                            <x-table.th>EMV</x-table.th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($rows as $row)
                            <tr wire:key="product-{{ $row->bucket_start }}-{{ $row->product_id }}">
                                <td class="px-5 py-3 text-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ $productNames[$row->product_id] ?? '#'.$row->product_id }}
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $row->bucket_start }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ number_format((int) ($row->shipments ?? 0)) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ number_format((int) ($row->posted_count ?? 0)) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->post_rate !== null)
                                        {{ number_format((float) $row->post_rate * 100, 1) }}% <x-metric.tier-badge tier="DERIVED" />
                                    @else
                                        <x-states.unavailable reason="No shipments in this bucket — a post rate without a base is never fabricated." />
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ number_format((int) ($row->creators_reached ?? 0)) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ number_format((int) $row->content_count) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->total_views !== null)
                                        {{ number_format((float) $row->total_views) }} <x-metric.tier-badge tier="PUBLIC" />
                                    @else
                                        <x-states.unavailable reason="No observed views for this product in this bucket — never shown as zero." />
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->total_engagement !== null)
                                        {{ number_format((float) $row->total_engagement) }} <x-metric.tier-badge tier="DERIVED" />
                                    @else
                                        <x-states.unavailable reason="No observed engagement for this product in this bucket — never shown as zero." />
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->total_estimated_reach !== null)
                                        {{ number_format((float) $row->total_estimated_reach) }} <x-metric.tier-badge tier="ESTIMATED" />
                                    @else
                                        <x-states.unavailable reason="No estimated reach for this slice yet (REQ-M1-006)." />
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->total_emv !== null)
                                        {{ number_format((float) $row->total_emv, 2) }} <x-metric.tier-badge tier="ESTIMATED" />
                                    @else
                                        <x-states.unavailable reason="EMV requires an active configuration and calculated results (REQ-M1-011)." />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="border-t border-gray-100 px-6 py-4 dark:border-gray-800">
            {{-- AC-M1-011 convention: every EMV figure discloses the model + rates USED. --}}
            {{-- Deep-review M4: producing models, not the active config. --}}
            <x-metric.emv-disclosure :configurations="$emvConfigurations" />
            <p class="mt-2 text-theme-xs text-gray-400 dark:text-gray-500">
                Rollups refreshed {{ $rollupsRefreshedAt?->diffForHumans() ?? 'never' }}.
            </p>
        </div>
    </div>
</div>
