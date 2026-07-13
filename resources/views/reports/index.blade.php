<x-layouts.app title="Client Reports">
    <x-page-header title="Client Reports" />

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <x-states.empty title="No approved reports yet">
            No client reports exist: v1 ships no external client access (ADR-0016) —
            this containment area stays empty unless a superseding decision restores
            the approved-reports capability.
        </x-states.empty>
    </div>
</x-layouts.app>
