{{--
    Toast notifications. Livewire components emit
    `$this->dispatch('notify', type: 'success', message: '…')`, which reaches
    the browser as a window-level `notify` CustomEvent.
--}}
<div x-data="{
        toasts: [],
        add(event) {
            const id = Date.now() + Math.random();
            this.toasts.push({
                id,
                type: event.detail.type ?? 'success',
                message: event.detail.message ?? '',
            });
            setTimeout(() => this.remove(id), 4000);
        },
        remove(id) {
            this.toasts = this.toasts.filter((toast) => toast.id !== id);
        },
    }"
    x-on:notify.window="add($event)"
    class="pointer-events-none fixed top-6 right-6 z-999999 flex w-full max-w-xs flex-col gap-2"
    aria-live="polite">
    <template x-for="toast in toasts" :key="toast.id">
        <div class="pointer-events-auto flex items-start gap-3 rounded-xl border bg-white p-4 shadow-theme-lg dark:bg-gray-900"
            :class="toast.type === 'error'
                ? 'border-error-500/40'
                : 'border-success-500/40'"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-x-4 opacity-0"
            x-transition:enter-end="translate-x-0 opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0">
            <span :class="toast.type === 'error' ? 'text-error-500' : 'text-success-500'" class="mt-0.5">
                <svg x-show="toast.type !== 'error'" class="fill-current" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M10 1.66675C5.39765 1.66675 1.66669 5.39771 1.66669 10.0001C1.66669 14.6025 5.39765 18.3334 10 18.3334C14.6024 18.3334 18.3334 14.6025 18.3334 10.0001C18.3334 5.39771 14.6024 1.66675 10 1.66675ZM13.6134 8.28024C13.9063 7.98735 13.9063 7.51247 13.6134 7.21958C13.3205 6.92669 12.8456 6.92669 12.5527 7.21958L9.06248 10.7098L7.44731 9.09466C7.15442 8.80176 6.67954 8.80176 6.38665 9.09466C6.09376 9.38755 6.09376 9.86242 6.38665 10.1553L8.53215 12.3008C8.67281 12.4415 8.86357 12.5205 9.06248 12.5205C9.26139 12.5205 9.45215 12.4415 9.59281 12.3008L13.6134 8.28024Z" fill="" />
                </svg>
                <svg x-show="toast.type === 'error'" class="fill-current" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M10 1.66675C5.39765 1.66675 1.66669 5.39771 1.66669 10.0001C1.66669 14.6025 5.39765 18.3334 10 18.3334C14.6024 18.3334 18.3334 14.6025 18.3334 10.0001C18.3334 5.39771 14.6024 1.66675 10 1.66675ZM7.80748 6.74678C7.51459 6.45389 7.03971 6.45389 6.74682 6.74678C6.45393 7.03967 6.45393 7.51455 6.74682 7.80744L8.9394 10L6.74682 12.1926C6.45393 12.4855 6.45393 12.9604 6.74682 13.2533C7.03971 13.5462 7.51459 13.5462 7.80748 13.2533L10 11.0607L12.1926 13.2533C12.4855 13.5462 12.9604 13.5462 13.2533 13.2533C13.5462 12.9604 13.5462 12.4855 13.2533 12.1926L11.0607 10L13.2533 7.80744C13.5462 7.51455 13.5462 7.03967 13.2533 6.74678C12.9604 6.45389 12.4855 6.45389 12.1926 6.74678L10 8.93936L7.80748 6.74678Z" fill="" />
                </svg>
            </span>
            <p class="flex-1 text-sm text-gray-700 dark:text-gray-300" x-text="toast.message"></p>
            <button type="button" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                @click="remove(toast.id)" aria-label="Dismiss">
                <svg class="fill-current" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M6.21967 7.28131C5.92678 6.98841 5.92678 6.51354 6.21967 6.22065C6.51256 5.92775 6.98744 5.92775 7.28033 6.22065L11.999 10.9393L16.7176 6.22078C17.0105 5.92789 17.4854 5.92788 17.7782 6.22078C18.0711 6.51367 18.0711 6.98855 17.7782 7.28144L13.0597 12L17.7782 16.7186C18.0711 17.0115 18.0711 17.4863 17.7782 17.7792C17.4854 18.0721 17.0105 18.0721 16.7176 17.7792L11.999 13.0607L7.28033 17.7794C6.98744 18.0722 6.51256 18.0722 6.21967 17.7794C5.92678 17.4865 5.92678 17.0116 6.21967 16.7187L10.9384 12L6.21967 7.28131Z" fill="" />
                </svg>
            </button>
        </div>
    </template>
</div>
