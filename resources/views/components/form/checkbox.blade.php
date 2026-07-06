@props([
    'label' => null,
])

<label class="flex cursor-pointer items-center gap-3 text-sm font-medium text-gray-700 select-none dark:text-gray-400">
    <input type="checkbox"
        {{ $attributes->merge(['class' => 'h-5 w-5 cursor-pointer rounded-md border-gray-300 text-brand-500 focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900']) }} />
    @if ($label)
        <span>{{ $label }}</span>
    @else
        {{ $slot }}
    @endif
</label>
