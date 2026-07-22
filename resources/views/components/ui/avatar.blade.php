@props([
    // Person/entity name — drives both the initials and the (stable) colour.
    'name' => '',
])

@php
    $clean = trim((string) $name);

    $initials = collect(preg_split('/\s+/', $clean) ?: [])
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');

    // Soft tinted chips (dark text on a light tint) keep the initials legible
    // in both themes — green is intentionally excluded so the monogram never
    // reads as the "In active seeding" status tag. The colour is a stable hash
    // of the name, so one creator always keeps the same chip.
    $palette = [
        'bg-brand-100 text-brand-600 dark:bg-brand-500/20 dark:text-brand-300',
        'bg-blue-light-100 text-blue-light-700 dark:bg-blue-light-500/20 dark:text-blue-light-300',
        'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300',
        'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-300',
    ];
    $swatch = $palette[abs(crc32($clean)) % count($palette)];
@endphp

<span aria-hidden="true"
    {{ $attributes->merge(['class' => 'flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-semibold '.$swatch]) }}>
    {{ $initials !== '' ? $initials : '?' }}
</span>
