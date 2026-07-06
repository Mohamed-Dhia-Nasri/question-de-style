{{--
    A tier-labelled metric value (DP-001), or the mandatory "unavailable"
    state when the value is absent — never a misleading zero or blank.
--}}
@props([
    /** \App\Shared\ValueObjects\MetricValue|null */
    'metric' => null,
    /** Why the value is unavailable when $metric is null. */
    'reason' => 'Not measured — never shown as zero.',
    'decimals' => 0,
])

@if ($metric === null)
    <x-states.unavailable :reason="$reason" />
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5']) }}>
        <span class="font-medium text-gray-800 dark:text-white/90">{{ number_format($metric->amount, $decimals) }}</span>
        <x-metric.tier-badge :tier="$metric->tier" />
    </span>
@endif
