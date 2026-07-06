{{--
    Confirmation dialog for destructive actions. Render conditionally from the
    Livewire view (`@if($confirmingDeleteId !== null) … @endif`).
--}}
@props([
    'title' => 'Are you sure?',
    'confirmAction',
    'cancelAction',
    'confirmLabel' => 'Confirm',
])

<x-ui.modal :title="$title" :close-action="$cancelAction" max-width="md">
    <div class="text-sm text-gray-600 dark:text-gray-400">
        {{ $slot }}
    </div>

    <x-slot:footer>
        <x-ui.button variant="outline" wire:click="{{ $cancelAction }}" wire:loading.attr="disabled">
            Cancel
        </x-ui.button>
        <x-ui.button variant="danger" wire:click="{{ $confirmAction }}" wire:loading.attr="disabled"
            wire:target="{{ $confirmAction }}">
            {{ $confirmLabel }}
        </x-ui.button>
    </x-slot:footer>
</x-ui.modal>
