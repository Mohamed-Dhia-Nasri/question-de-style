{{-- One-line legend for the metric quality words (F21). --}}
<p {{ $attributes->merge(['class' => 'text-theme-xs text-gray-500 dark:text-gray-400']) }}>
    Every number shows where it comes from:
    <x-metric.tier-badge tier="PUBLIC" /> platform-reported ·
    <x-metric.tier-badge tier="DERIVED" /> calculated by QDS ·
    <x-metric.tier-badge tier="ESTIMATED" /> modelled estimate ·
    <x-metric.tier-badge tier="CONFIRMED" /> entered by your team.
</p>
