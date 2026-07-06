@props([
    'title' => 'Something went wrong',
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-12 text-center']) }}>
    <span class="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-error-50 text-error-500 dark:bg-error-500/15">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"
                stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </span>
    <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h3>
    @if (trim($slot) !== '')
        <p class="mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">{{ $slot }}</p>
    @endif
</div>
