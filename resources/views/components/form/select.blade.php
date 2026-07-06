@props([
    'error' => false,
])

@php
    $base = 'h-11 w-full appearance-none rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90';

    $stateClass = $error
        ? 'border-error-500 focus:border-error-300 focus:ring-error-500/10 dark:border-error-500'
        : 'border-gray-300 focus:border-brand-300 focus:ring-brand-500/10 dark:border-gray-700 dark:focus:border-brand-800';
@endphp

<div class="relative">
    <select {{ $attributes->merge(['class' => "{$base} {$stateClass} pr-11"]) }}>
        {{ $slot }}
    </select>
    <span class="pointer-events-none absolute top-1/2 right-4 -translate-y-1/2 text-gray-500 dark:text-gray-400">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </span>
</div>
