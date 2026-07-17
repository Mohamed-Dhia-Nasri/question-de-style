<div>
    @can('create', \App\Modules\CRM\Models\SeedingCampaign::class)
        <x-ui.button size="sm" wire:click="create">+ New seeding run</x-ui.button>
    @endcan

    @if ($showForm)
        <x-ui.modal title="New seeding run" close-action="cancelForm">
            <form wire:submit="save" class="space-y-5">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    For {{ $campaign->name }} — {{ $campaign->brand->name }}. It starts as a draft.
                </p>

                <div>
                    <x-form.label for="run_name" required>Name</x-form.label>
                    <x-form.input id="run_name" wire:model="run_name" :error="$errors->has('run_name')" />
                    <x-form.error for="run_name" />
                </div>

                <div x-data="{ s: @js($run_type), map: @js($typeDescriptions) }">
                    <x-form.label for="run_type" required>Seeding type</x-form.label>
                    <x-form.select id="run_type" wire:model="run_type" x-on:change="s = $event.target.value"
                        :error="$errors->has('run_type')">
                        <option value="">Select a seeding type…</option>
                        @foreach (\App\Shared\Enums\SeedingType::cases() as $typeOption)
                            <option value="{{ $typeOption->value }}">{{ $typeOption->label() }}</option>
                        @endforeach
                    </x-form.select>
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400" x-text="map[s] ?? ''"></p>
                    <x-form.error for="run_type" />
                </div>

                <div>
                    <x-form.label for="run_product_id">Product</x-form.label>
                    <x-form.select id="run_product_id" wire:model="run_product_id" :error="$errors->has('run_product_id')">
                        <option value="">No product yet</option>
                        @foreach ($products as $productOption)
                            <option value="{{ $productOption->id }}">{{ $productOption->name }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.error for="run_product_id" />
                    @can('create', \App\Modules\CRM\Models\Product::class)
                        <button type="button" wire:click="openInlineCreate('product')"
                            class="mt-1.5 text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">+ New product</button>
                    @endcan
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelForm" wire:loading.attr="disabled">Cancel</x-ui.button>
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Create seeding run</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-crm.inline-create :type="$inlineCreate" />
</div>
