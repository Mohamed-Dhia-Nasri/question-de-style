{{--
    ENUM-MetricTier badge (DP-001): every displayed metric carries its tier;
    an ESTIMATED value is never presented as fact. Values canonical in
    docs/00-meta/03-glossary.md#enum-metrictier.
--}}
@props([
    /** string|\App\Shared\Enums\MetricTier */
    'tier',
])

@php
    $value = $tier instanceof \App\Shared\Enums\MetricTier ? $tier->value : (string) $tier;

    $color = match ($value) {
        'PUBLIC' => 'info',
        'DERIVED' => 'primary',
        'ESTIMATED' => 'warning',
        'CONFIRMED' => 'success',
        default => 'light',
    };
@endphp

<x-ui.badge :color="$color" size="sm" {{ $attributes }}>{{ $value }}</x-ui.badge>
