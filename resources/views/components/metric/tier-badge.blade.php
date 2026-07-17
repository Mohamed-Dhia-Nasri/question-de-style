{{--
    ENUM-MetricTier badge (DP-001): every displayed metric carries its tier;
    an ESTIMATED value is never presented as fact. Plain words per the
    2026-07-16 CRM UX audit (F21); values canonical in the glossary.
--}}
@props([
    /** string|\App\Shared\Enums\MetricTier */
    'tier',
])

@php
    $enum = $tier instanceof \App\Shared\Enums\MetricTier
        ? $tier
        : \App\Shared\Enums\MetricTier::tryFrom((string) $tier);

    $color = match ($enum) {
        \App\Shared\Enums\MetricTier::Public => 'info',
        \App\Shared\Enums\MetricTier::Derived => 'primary',
        \App\Shared\Enums\MetricTier::Estimated => 'warning',
        \App\Shared\Enums\MetricTier::Confirmed => 'success',
        default => 'light',
    };
@endphp

<x-ui.badge :color="$color" size="sm" :title="$enum?->description()" {{ $attributes }}>{{ $enum?->label() ?? $tier }}</x-ui.badge>
