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
        <x-states.unavailable reason="EMV model disclosure: no EMV has been computed yet (REQ-M1-011 — EMV requires a user-activated, transparent rate card and a calculation run)." />
    @else
        @if ($configurations->count() > 1)
            <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                EMV figures across current QDS results span {{ $configurations->count() }} rate cards (workspace-wide disclosure):
            </p>
        @endif
        @foreach ($configurations as $configuration)
            <p class="text-theme-xs text-gray-500 dark:text-gray-400" wire:key="emv-disclosure-{{ $configuration->id }}">
                EMV model "{{ $configuration->name }}" · formula {{ $configuration->formula_version }} ·
                rate card {{ $configuration->rate_card_version }} · currency {{ $configuration->currency }}.
                Rates: {{ json_encode($configuration->rates) }}
            </p>
        @endforeach
    @endif
</div>
