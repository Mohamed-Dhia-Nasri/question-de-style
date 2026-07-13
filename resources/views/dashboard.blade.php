<x-layouts.app title="Dashboard">
    <x-page-header title="Dashboard" />

    {{-- Live tiles (Monitoring P1 + CRM & Seeding P3 shipped) — replaces
         the static P0 placeholder. Rendering rules per DP-001: entity
         counts are true counts; the reach aggregate is rollup-backed and
         tier-labelled, never fabricated (DEF-003). --}}
    <livewire:monitoring.home-overview />
</x-layouts.app>
