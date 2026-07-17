{{--
    EMV model disclosure (GL-EMV / AC-M1-011: every report shows the model +
    rates USED). Cites the configurations that PRODUCED the figures on
    screen — the models behind the latest emv_results per content — not the
    merely-active configuration, which can diverge after a rate-card change
    without recalculation (deep-review finding M4). No producing model means
    no EMV has been computed: the disclosure is unavailable, never implied.

    @props Collection<int, EmvConfiguration> $configurations
--}}
@props(['configurations'])

<div {{ $attributes }}>
    @if ($configurations->isEmpty())
        <x-states.unavailable reason="No EMV yet — EMV is calculated once rates are set up under Settings → EMV." />
    @else
        @if ($configurations->count() > 1)
            <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                These figures use {{ $configurations->count() }} different rate cards (the settings changed over time):
            </p>
        @endif
        @foreach ($configurations as $configuration)
            <p class="text-theme-xs text-gray-500 dark:text-gray-400" wire:key="emv-disclosure-{{ $configuration->id }}">
                Earned Media Value (EMV) estimated with the “{{ $configuration->name }}” rate card ({{ $configuration->currency }}).
                @can(\App\Shared\Authorization\PermissionsCatalog::SETTINGS_VIEW)
                    <a href="{{ route('settings.emv') }}" class="text-brand-500 hover:text-brand-600 dark:text-brand-400" wire:navigate>View or change the rates</a>.
                @endcan
            </p>
        @endforeach
    @endif
</div>
