<div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4 dark:border-gray-800">
        <div>
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Documents</h3>
            <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                Contracts, briefs, and other files — stored privately, downloads use
                short-lived links.
            </p>
        </div>

        @can('create', \App\Modules\CRM\Models\DocumentAttachment::class)
            <x-ui.button size="sm" wire:click="openForm">Upload document</x-ui.button>
        @endcan
    </div>

    @if ($documents->isEmpty())
        <x-states.empty title="No documents yet">
            Upload a contract or brief — it stays linked to this record.
        </x-states.empty>
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[600px]">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-800">
                        <x-table.th>File</x-table.th>
                        <x-table.th>Size</x-table.th>
                        <x-table.th>Uploaded</x-table.th>
                        <x-table.th><span class="sr-only">Actions</span></x-table.th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($documents as $document)
                        <tr wire:key="document-{{ $document->id }}">
                            <td class="px-5 py-4">
                                {{-- Signed, short-lived download link (never a public URL). --}}
                                <a href="{{ $this->downloadUrl($document) }}"
                                    class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                    {{ $document->file_name }}
                                </a>
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                @if ($sizes[$document->id] !== null)
                                    {{ number_format($sizes[$document->id] / 1024, 1, ',', '.') }} KB
                                @else
                                    &mdash;
                                @endif
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ $document->uploaded_at->format('d.m.Y H:i') }}
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    @can('delete', $document)
                                        <button type="button" wire:click="confirmDelete({{ $document->id }})"
                                            class="text-sm font-medium text-error-500 hover:text-error-600">
                                            Delete
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Upload --}}
    @if ($showForm)
        <x-ui.modal title="Upload document" close-action="cancelForm">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <x-form.label for="upload" required>File</x-form.label>
                    <x-form.input id="upload" wire:model="upload" type="file" :error="$errors->has('upload')" />
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                        Up to 10 MB — pdf, doc/docx, xls/xlsx, csv, png, jpg/jpeg, zip.
                    </p>
                    <x-form.error for="upload" />
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="cancelForm" wire:loading.attr="disabled">Cancel</x-ui.button>
                <x-ui.button wire:click="save" wire:loading.attr="disabled" wire:target="save, upload">
                    <span wire:loading.remove wire:target="save, upload">Upload</span>
                    <span wire:loading wire:target="save, upload">Uploading…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- Delete confirmation --}}
    @if ($confirmingDeleteId !== null)
        <x-ui.confirm-modal title="Delete document?" confirm-action="delete" cancel-action="cancelDelete"
            confirm-label="Delete document">
            This removes the stored file and its record. The action is recorded in the audit log.
        </x-ui.confirm-modal>
    @endif
</div>
