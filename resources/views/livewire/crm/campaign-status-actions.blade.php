<div>
    @if ($suggestion)
        <x-ui.alert variant="info">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <span class="text-gray-700 dark:text-gray-300">{{ $suggestion['label'] }}</span>
                <x-ui.button variant="primary" size="sm" class="shrink-0"
                    wire:click="applyStatus" wire:confirm="Change the status?">
                    {{ $suggestion['cta'] }}
                </x-ui.button>
            </div>
        </x-ui.alert>
    @endif
</div>
