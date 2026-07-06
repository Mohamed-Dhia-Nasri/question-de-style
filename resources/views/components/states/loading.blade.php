@props([
    'label' => 'Loading…',
])

<div {{ $attributes->merge(['class' => 'flex items-center justify-center gap-3 px-6 py-8 text-gray-500 dark:text-gray-400']) }}>
    <span class="h-5 w-5 animate-spin rounded-full border-2 border-solid border-brand-500 border-t-transparent"></span>
    <span class="text-sm">{{ $label }}</span>
</div>
