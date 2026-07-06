@props([
    'variant' => 'info',
    'title' => null,
])

@php
    $variantMap = [
        'success' => 'border-success-500 bg-success-50 dark:border-success-500/30 dark:bg-success-500/15',
        'error' => 'border-error-500 bg-error-50 dark:border-error-500/30 dark:bg-error-500/15',
        'warning' => 'border-warning-500 bg-warning-50 dark:border-warning-500/30 dark:bg-warning-500/15',
        'info' => 'border-blue-light-500 bg-blue-light-50 dark:border-blue-light-500/30 dark:bg-blue-light-500/15',
    ];

    $iconColorMap = [
        'success' => 'text-success-500',
        'error' => 'text-error-500',
        'warning' => 'text-warning-500',
        'info' => 'text-blue-light-500',
    ];

    $variantClass = $variantMap[$variant] ?? $variantMap['info'];
    $iconColor = $iconColorMap[$variant] ?? $iconColorMap['info'];
@endphp

<div {{ $attributes->merge(['class' => "rounded-xl border-l-4 p-4 {$variantClass}"]) }} role="alert">
    <div class="flex items-start gap-3">
        <span class="{{ $iconColor }} mt-0.5">
            @if ($variant === 'success')
                <svg class="fill-current" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M10 1.66675C5.39765 1.66675 1.66669 5.39771 1.66669 10.0001C1.66669 14.6025 5.39765 18.3334 10 18.3334C14.6024 18.3334 18.3334 14.6025 18.3334 10.0001C18.3334 5.39771 14.6024 1.66675 10 1.66675ZM13.6134 8.28024C13.9063 7.98735 13.9063 7.51247 13.6134 7.21958C13.3205 6.92669 12.8456 6.92669 12.5527 7.21958L9.06248 10.7098L7.44731 9.09466C7.15442 8.80176 6.67954 8.80176 6.38665 9.09466C6.09376 9.38755 6.09376 9.86242 6.38665 10.1553L8.53215 12.3008C8.67281 12.4415 8.86357 12.5205 9.06248 12.5205C9.26139 12.5205 9.45215 12.4415 9.59281 12.3008L13.6134 8.28024Z" fill="" />
                </svg>
            @elseif ($variant === 'error')
                <svg class="fill-current" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M10 1.66675C5.39765 1.66675 1.66669 5.39771 1.66669 10.0001C1.66669 14.6025 5.39765 18.3334 10 18.3334C14.6024 18.3334 18.3334 14.6025 18.3334 10.0001C18.3334 5.39771 14.6024 1.66675 10 1.66675ZM7.80748 6.74678C7.51459 6.45389 7.03971 6.45389 6.74682 6.74678C6.45393 7.03967 6.45393 7.51455 6.74682 7.80744L8.9394 10L6.74682 12.1926C6.45393 12.4855 6.45393 12.9604 6.74682 13.2533C7.03971 13.5462 7.51459 13.5462 7.80748 13.2533L10 11.0607L12.1926 13.2533C12.4855 13.5462 12.9604 13.5462 13.2533 13.2533C13.5462 12.9604 13.5462 12.4855 13.2533 12.1926L11.0607 10L13.2533 7.80744C13.5462 7.51455 13.5462 7.03967 13.2533 6.74678C12.9604 6.45389 12.4855 6.45389 12.1926 6.74678L10 8.93936L7.80748 6.74678Z" fill="" />
                </svg>
            @else
                <svg class="fill-current" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M10 1.66675C5.39765 1.66675 1.66669 5.39771 1.66669 10.0001C1.66669 14.6025 5.39765 18.3334 10 18.3334C14.6024 18.3334 18.3334 14.6025 18.3334 10.0001C18.3334 5.39771 14.6024 1.66675 10 1.66675ZM10 5.83341C10.4603 5.83341 10.8334 6.20651 10.8334 6.66675V10.8334C10.8334 11.2937 10.4603 11.6667 10 11.6667C9.53978 11.6667 9.16669 11.2937 9.16669 10.8334V6.66675C9.16669 6.20651 9.53978 5.83341 10 5.83341ZM10 14.1667C10.4603 14.1667 10.8334 13.7937 10.8334 13.3334C10.8334 12.8732 10.4603 12.5001 10 12.5001C9.53978 12.5001 9.16669 12.8732 9.16669 13.3334C9.16669 13.7937 9.53978 14.1667 10 14.1667Z" fill="" />
                </svg>
            @endif
        </span>
        <div class="flex-1">
            @if ($title)
                <h4 class="mb-1 text-sm font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h4>
            @endif
            <div class="text-sm text-gray-600 dark:text-gray-400">{{ $slot }}</div>
        </div>
    </div>
</div>
