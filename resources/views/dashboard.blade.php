<x-layouts.app title="Dashboard">
    <x-page-header title="Dashboard" />

    {{-- Live tiles (Monitoring P1 + CRM & Seeding P3 shipped) — replaces
         the static P0 placeholder. Rendering rules per DP-001: entity
         counts are true counts; the reach aggregate is rollup-backed and
         tier-labelled ESTIMATED per ADR-0022, never fabricated. CONFIRMED
         unique reach stays deferred per DEF-003. --}}
    <livewire:monitoring.home-overview />
</x-layouts.app>
