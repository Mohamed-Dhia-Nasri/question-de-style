@props([
    'title' => 'Nothing here yet',
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-12 text-center']) }}>
    <span class="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/5 dark:text-gray-500">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M20 13V7a2 2 0 00-2-2H6a2 2 0 00-2 2v6m16 0v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4m16 0h-4.5a1.5 1.5 0 00-1.5 1.5 1.5 1.5 0 01-1.5 1.5h-1a1.5 1.5 0 01-1.5-1.5A1.5 1.5 0 008.5 13H4"
                stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </span>
    <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h3>
    @if (trim($slot) !== '')
        <p class="mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">{{ $slot }}</p>
    @endif
    @isset($action)
        <div class="mt-5">{{ $action }}</div>
    @endisset
</div>
