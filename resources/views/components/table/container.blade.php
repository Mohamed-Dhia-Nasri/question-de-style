@props([])

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]']) }}>
    @isset($header)
        <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-800">
            {{ $header }}
        </div>
    @endisset

    <div class="max-w-full overflow-x-auto">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="border-t border-gray-200 px-5 py-4 dark:border-gray-800">
            {{ $footer }}
        </div>
    @endisset
</div>
