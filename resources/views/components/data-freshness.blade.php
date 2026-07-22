@props([
    // Carbon|CarbonImmutable|string|null — the moment the data was last
    // pulled/refreshed. Null (or empty) renders the `never` fallback.
    'at' => null,
    // Leading label, e.g. "Data updated", "Data refreshed", "Updated".
    'label' => 'Updated',
    // Text shown when there is no timestamp yet.
    'never' => 'never',
])

@php
    // Accepts a Carbon instance or a raw DB string (e.g. from ->max()). Times
    // are stored and shown in UTC (config/app.php timezone = UTC) — the "UTC"
    // suffix makes that explicit so the value is never misread as local time.
    $freshnessAt = $at ? \Illuminate\Support\Carbon::parse($at) : null;
@endphp

<span {{ $attributes->merge(['class' => 'text-theme-xs text-gray-400 dark:text-gray-500']) }}>
    {{ $label }}
    @if ($freshnessAt)
        <time datetime="{{ $freshnessAt->toIso8601String() }}"
            title="{{ $freshnessAt->diffForHumans() }}">{{ $freshnessAt->format('d.m.Y H:i') }} UTC</time>
    @else
        {{ $never }}
    @endif
</span>
