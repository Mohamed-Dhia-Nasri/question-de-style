@props([
    'title',
    /** array<string, string|null> label => url (null = current page) */
    'breadcrumbs' => [],
])

<div {{ $attributes->merge(['class' => 'mb-6 flex flex-wrap items-center justify-between gap-3']) }}>
    <div>
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h2>

        @if ($breadcrumbs !== [])
            <nav class="mt-1" aria-label="Breadcrumb">
                <ol class="flex items-center gap-1.5">
                    @foreach ($breadcrumbs as $label => $url)
                        <li class="flex items-center gap-1.5 text-theme-sm {{ $loop->last ? 'text-gray-800 dark:text-white/90' : 'text-gray-500 dark:text-gray-400' }}">
                            @if (! $loop->first)
                                <svg class="stroke-current text-gray-400" width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            @endif
                            @if ($url && ! $loop->last)
                                <a href="{{ $url }}" class="hover:text-gray-800 dark:hover:text-white/90">{{ $label }}</a>
                            @else
                                <span @if ($loop->last) aria-current="page" @endif>{{ $label }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </nav>
        @endif
    </div>

    @isset($actions)
        <div class="flex items-center gap-3">
            {{ $actions }}
        </div>
    @endisset
</div>
