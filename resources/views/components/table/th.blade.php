{{--
    Table header cell. With `field`, renders a sort button wired to the
    WithDataTable trait's sortBy() (whitelisted server-side).
--}}
@props([
    'field' => null,
    'sortField' => null,
    'sortDirection' => 'asc',
])

<th {{ $attributes->merge(['class' => 'px-5 py-3 text-left']) }}>
    @if ($field)
        <button type="button" wire:click="sortBy('{{ $field }}')"
            class="flex items-center gap-1 text-theme-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
            {{ $slot }}
            @if ($sortField === $field)
                @if ($sortDirection === 'asc')
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 19V5m0 0l-6 6m6-6l6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                @else
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 5v14m0 0l6-6m-6 6l-6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                @endif
            @else
                <svg class="opacity-40" width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M8 9l4-4 4 4M8 15l4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            @endif
        </button>
    @else
        <span class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $slot }}</span>
    @endif
</th>
