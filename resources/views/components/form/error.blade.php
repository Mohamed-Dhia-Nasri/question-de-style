@props([
    'for',
])

@error($for)
    <p {{ $attributes->merge(['class' => 'mt-1.5 text-theme-xs text-error-500']) }}>{{ $message }}</p>
@enderror
