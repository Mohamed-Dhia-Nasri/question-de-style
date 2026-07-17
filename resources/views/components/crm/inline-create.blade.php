{{--
    Shared inline "+ create" modal (CRM UX Stage C, F01a). Rendered AFTER the
    host's own modal and driven entirely by the host's WithInlineCreate trait
    state — the `inline_*` field names are part of that contract, so this
    component binds them directly. Renders nothing until a form is opened.
--}}
@props([
    'type' => null,
    'clients' => null,
    'newClient' => false,
])

@php
    $titles = [
        'client' => 'New client',
        'brand' => 'New brand',
        'product' => 'New product',
        'campaign' => 'New campaign',
    ];
    $hasClients = $clients !== null && count($clients) > 0;
@endphp

@if ($type !== null)
    <x-ui.modal :title="$titles[$type] ?? 'Create'" close-action="cancelInlineCreate" max-width="md">
        <form wire:submit="saveInlineCreate" class="space-y-4">
            @if ($type === 'client')
                <div>
                    <x-form.label for="inline_client_name" required>Name</x-form.label>
                    <x-form.input id="inline_client_name" wire:model="inline_client_name"
                        :error="$errors->has('inline_client_name')" />
                    <x-form.error for="inline_client_name" />
                </div>

                <div>
                    <x-form.label for="inline_client_country">Country</x-form.label>
                    <x-form.select id="inline_client_country" wire:model="inline_client_country"
                        :error="$errors->has('inline_client_country')">
                        <option value="">No country</option>
                        @foreach (\App\Shared\Enums\Country::cases() as $countryOption)
                            <option value="{{ $countryOption->value }}">{{ $countryOption->name }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.error for="inline_client_country" />
                </div>
            @elseif ($type === 'brand')
                <div>
                    <x-form.label for="inline_brand_name" required>Name</x-form.label>
                    <x-form.input id="inline_brand_name" wire:model="inline_brand_name"
                        :error="$errors->has('inline_brand_name')" />
                    <x-form.error for="inline_brand_name" />
                </div>

                @if ($newClient || ! $hasClients)
                    <div>
                        <x-form.label for="inline_client_name" required>Client name</x-form.label>
                        <x-form.input id="inline_client_name" wire:model="inline_client_name"
                            :error="$errors->has('inline_client_name')" />
                        <x-form.error for="inline_client_name" />
                        @if ($hasClients)
                            <button type="button" wire:click="$set('inline_new_client', false)"
                                class="mt-1.5 text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">Pick an existing client instead</button>
                        @endif
                    </div>
                @else
                    <div>
                        <x-form.label for="inline_brand_client_id" required>Client</x-form.label>
                        <x-form.select id="inline_brand_client_id" wire:model="inline_brand_client_id"
                            :error="$errors->has('inline_brand_client_id')">
                            <option value="">Select a client…</option>
                            @foreach ($clients as $clientOption)
                                <option value="{{ $clientOption->id }}">{{ $clientOption->name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-form.error for="inline_brand_client_id" />
                        <button type="button" wire:click="$set('inline_new_client', true)"
                            class="mt-1.5 text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">+ New client</button>
                    </div>
                @endif
            @elseif ($type === 'product')
                <div>
                    <x-form.label for="inline_product_name" required>Name</x-form.label>
                    <x-form.input id="inline_product_name" wire:model="inline_product_name"
                        :error="$errors->has('inline_product_name')" />
                    <x-form.error for="inline_product_name" />
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Created under the brand you’ve chosen.</p>
            @elseif ($type === 'campaign')
                <div>
                    <x-form.label for="inline_campaign_name" required>Name</x-form.label>
                    <x-form.input id="inline_campaign_name" wire:model="inline_campaign_name"
                        :error="$errors->has('inline_campaign_name')" />
                    <x-form.error for="inline_campaign_name" />
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Created under the brand you’ve chosen.</p>
            @endif
        </form>

        <x-slot:footer>
            <x-ui.button variant="outline" wire:click="cancelInlineCreate" wire:loading.attr="disabled">Cancel</x-ui.button>
            <x-ui.button wire:click="saveInlineCreate" wire:loading.attr="disabled" wire:target="saveInlineCreate">
                <span wire:loading.remove wire:target="saveInlineCreate">Create</span>
                <span wire:loading wire:target="saveInlineCreate">Saving…</span>
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endif
