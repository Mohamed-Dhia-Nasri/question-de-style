<x-layouts.guest title="Invitation unavailable">
    <div>
        <h1 class="mb-2 text-title-sm font-semibold text-gray-800 dark:text-white/90">Invitation unavailable</h1>
        <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">{{ $reason }}</p>

        <a href="{{ route('login') }}"
            class="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
            Go to sign in
        </a>
    </div>
</x-layouts.guest>
