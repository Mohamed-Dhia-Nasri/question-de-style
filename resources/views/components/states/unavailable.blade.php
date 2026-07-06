{{--
    Mandatory UI state for deferred (DEF-*) capabilities: renders the literal
    "unavailable" — never an empty cell, never zero, never a fabricated value.
    Canonical rule: docs/20-cross-cutting/01-deferred-register.md.
--}}
@props([
    /** Why the field is unavailable, citing its DEF-* id. */
    'reason',
])

<span {{ $attributes->merge(['class' => 'inline-flex cursor-help items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-0.5 text-theme-xs font-medium text-gray-500 dark:bg-white/5 dark:text-gray-400']) }}
    title="{{ $reason }}">
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M12 16v-4m0-4h.01M22 12a10 10 0 11-20 0 10 10 0 0120 0z"
            stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
    unavailable
    <span class="sr-only">— {{ $reason }}</span>
</span>
