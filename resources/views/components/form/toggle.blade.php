@props([
    'label' => null,
])

<label class="flex cursor-pointer items-center gap-3 select-none">
    <div class="relative">
        <input type="checkbox" class="peer sr-only" {{ $attributes }} />
        <div class="h-6 w-11 rounded-full bg-gray-200 transition-colors peer-checked:bg-brand-500 dark:bg-white/10"></div>
        <div class="absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow-theme-sm transition-transform duration-150 peer-checked:translate-x-full"></div>
    </div>
    @if ($label)
        <span class="text-sm font-medium text-gray-700 dark:text-gray-400">{{ $label }}</span>
    @endif
</label>
