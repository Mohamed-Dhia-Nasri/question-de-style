<?php

namespace App\Platform\Enrichment\TextSignals;

/**
 * Detects gifting/PR disclosure phrases in a caption across the configured
 * languages (config qds.enrichment.text_signals.gifting_cues). Matching is
 * whole-word and diacritic-folded. Emits 'gifting-cue:<phrase>' signals —
 * a relevance/context booster, never a brand claim.
 */
final class ContextualCueDetector
{
    /** @return list<string> */
    public function detect(?string $caption): array
    {
        if (! is_string($caption) || trim($caption) === '') {
            return [];
        }

        $haystack = self::fold($caption);
        $out = [];

        foreach ((array) config('qds.enrichment.text_signals.gifting_cues', []) as $phrases) {
            foreach ((array) $phrases as $phrase) {
                $folded = self::fold((string) $phrase);

                if ($folded === '') {
                    continue;
                }

                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($folded, '/').'(?![\p{L}\p{N}])/u', $haystack) === 1) {
                    $signal = 'gifting-cue:'.$folded;

                    if (! in_array($signal, $out, true)) {
                        $out[] = $signal;
                    }
                }
            }
        }

        return $out;
    }

    private static function fold(string $s): string
    {
        $n = \Normalizer::normalize($s, \Normalizer::FORM_KD);
        $n = is_string($n) ? $n : $s;

        return mb_strtolower(preg_replace('/\p{Mn}+/u', '', $n) ?? $n);
    }
}
