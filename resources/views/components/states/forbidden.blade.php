<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-12 text-center']) }}>
    <span class="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-warning-50 text-warning-600 dark:bg-warning-500/15">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v2h8z"
                stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </span>
    <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">You don't have access to this area</h3>
    <p class="mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">
        Your role doesn't include this permission. If you believe it should, contact an administrator.
    </p>
</div>
