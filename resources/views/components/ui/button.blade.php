@props([
    'variant' => 'primary',
    'size' => 'md',
    'disabled' => false,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition';

    $sizeMap = [
        'sm' => 'px-4 py-2.5 text-theme-sm',
        'md' => 'px-5 py-3 text-sm',
    ];
    $sizeClass = $sizeMap[$size] ?? $sizeMap['md'];

    $variantMap = [
        'primary' => 'bg-brand-500 text-white shadow-theme-xs hover:bg-brand-600 disabled:bg-brand-300',
        'outline' => 'bg-white text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03] dark:hover:text-gray-300',
        'danger' => 'bg-error-500 text-white shadow-theme-xs hover:bg-error-600 disabled:bg-error-300',
    ];
    $variantClass = $variantMap[$variant] ?? $variantMap['primary'];

    $disabledClass = $disabled ? 'cursor-not-allowed opacity-50' : '';

    $classes = trim("{$base} {$sizeClass} {$variantClass} {$disabledClass}");
@endphp

<button {{ $attributes->merge(['class' => $classes, 'type' => $attributes->get('type', 'button')]) }}
    @if ($disabled) disabled @endif>
    {{ $slot }}
</button>
