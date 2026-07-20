<div>
    @if ($product !== null)
        <x-ui.modal title="Photos — {{ $product->name }}" close-action="close">
            <div class="space-y-5">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Add 3–5 diverse views (front, back, side, packaging, real-world use) so the
                    detector can recognize this product from any angle. JPG, PNG, or WebP, up to
                    10&nbsp;MB — {{ $photoCap }} photos max.
                </p>

                @if ($photos->isEmpty())
                    <x-states.empty title="No photos yet">
                        Upload the first reference photo below.
                    </x-states.empty>
                @else
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        @foreach ($photos as $photo)
                            <div wire:key="product-photo-{{ $photo->id }}" class="space-y-1.5">
                                <img src="{{ $this->thumbnailUrl($photo) }}"
                                    alt="{{ $photo->view_label?->value ?? 'Product photo' }}"
                                    class="aspect-square w-full rounded-lg border border-gray-200 object-cover dark:border-gray-800" />
                                <div class="flex items-center justify-between">
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $photo->view_label !== null ? ucwords(str_replace('_', ' ', $photo->view_label->value)) : '—' }}
                                    </span>
                                    @can('update', $product)
                                        <button type="button" wire:click="confirmDelete({{ $photo->id }})"
                                            class="text-theme-xs font-medium text-error-500 hover:text-error-600">Delete</button>
                                    @endcan
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @can('update', $product)
                    @if ($photos->count() < $photoCap)
                        <form wire:submit="save" class="space-y-4 border-t border-gray-200 pt-4 dark:border-gray-800">
                            <div>
                                <x-form.label for="photo_upload" required>Photo</x-form.label>
                                <input id="photo_upload" type="file" wire:model="upload" accept=".jpg,.jpeg,.png,.webp"
                                    class="block w-full text-sm text-gray-500 dark:text-gray-400" />
                                <x-form.error for="upload" />
                            </div>

                            <div>
                                <x-form.label for="photo_view_label">View</x-form.label>
                                <x-form.select id="photo_view_label" wire:model="view_label" :error="$errors->has('view_label')">
                                    <option value="">No label</option>
                                    @foreach ($viewLabels as $labelOption)
                                        <option value="{{ $labelOption->value }}">{{ ucwords(str_replace('_', ' ', $labelOption->value)) }}</option>
                                    @endforeach
                                </x-form.select>
                                <x-form.error for="view_label" />
                            </div>

                            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save, upload">
                                <span wire:loading.remove wire:target="save">Add photo</span>
                                <span wire:loading wire:target="save">Uploading…</span>
                            </x-ui.button>
                        </form>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            This product has reached the {{ $photoCap }}-photo limit. Delete one to add another.
                        </p>
                    @endif
                @endcan
            </div>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="close">Close</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($confirmingDeleteId !== null)
        <x-ui.confirm-modal title="Delete photo?" confirm-action="deletePhoto" cancel-action="cancelDelete"
            confirm-label="Delete photo">
            The photo and its stored embeddings are removed. Matching for this product uses the
            remaining photos.
        </x-ui.confirm-modal>
    @endif
</div>
