{{--
    Presentational modal for Livewire flows: render it conditionally from the
    component view (`@if($showForm) … @endif`) so open/close state stays
    server-driven and survives DOM morphing. `closeAction` is the wire method
    invoked by the ✕ button, the overlay, and the Escape key.
--}}
@props([
    'title' => null,
    'closeAction' => null,
    'maxWidth' => 'lg',
])

@php
    $maxWidthClass = [
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
    ][$maxWidth] ?? 'max-w-lg';
@endphp

<div class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto p-4"
    x-data
    @if ($closeAction) @keydown.escape.window="$wire.{{ $closeAction }}()" @endif
    role="dialog" aria-modal="true" @if ($title) aria-label="{{ $title }}" @endif>
    {{-- Overlay --}}
    <div class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm"
        @if ($closeAction) @click="$wire.{{ $closeAction }}()" @endif></div>

    {{-- Panel --}}
    <div class="relative w-full {{ $maxWidthClass }} rounded-2xl bg-white p-6 shadow-theme-lg dark:bg-gray-900">
        <div class="mb-5 flex items-start justify-between gap-4">
            @if ($title)
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h3>
            @endif

            @if ($closeAction)
                <button type="button" wire:click="{{ $closeAction }}" aria-label="Close"
                    class="flex h-9 w-9 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/5 dark:hover:text-gray-300">
                    <svg class="fill-current" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" clip-rule="evenodd" d="M6.21967 7.28131C5.92678 6.98841 5.92678 6.51354 6.21967 6.22065C6.51256 5.92775 6.98744 5.92775 7.28033 6.22065L11.999 10.9393L16.7176 6.22078C17.0105 5.92789 17.4854 5.92788 17.7782 6.22078C18.0711 6.51367 18.0711 6.98855 17.7782 7.28144L13.0597 12L17.7782 16.7186C18.0711 17.0115 18.0711 17.4863 17.7782 17.7792C17.4854 18.0721 17.0105 18.0721 16.7176 17.7792L11.999 13.0607L7.28033 17.7794C6.98744 18.0722 6.51256 18.0722 6.21967 17.7794C5.92678 17.4865 5.92678 17.0116 6.21967 16.7187L10.9384 12L6.21967 7.28131Z" fill="" />
                    </svg>
                </button>
            @endif
        </div>

        {{-- Autofocus the first field of the CONTENT (not the ✕ button above) --}}
        <div x-init="$nextTick(() => $el.querySelector('input, select, textarea, button')?.focus())">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="mt-6 flex items-center justify-end gap-3">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
