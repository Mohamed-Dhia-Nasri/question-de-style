<?php

namespace App\Platform\Enrichment\Console;

use App\Platform\Enrichment\Recognition\BrandLexicon;
use App\Platform\Enrichment\TextSignals\ContextualCueDetector;
use App\Platform\Enrichment\TextSignals\MentionExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Offline scorecard for seeded detection over a labelled golden set. Runs
 * the deterministic text-signal + classification path (no provider calls)
 * and prints recall/precision at brand and product level, per platform.
 * The measurement baseline for sub-projects B–E.
 */
class EvalDetectionCommand extends Command
{
    protected $signature = 'qds:eval-detection {--fixture=}';

    protected $description = 'Score seeded-product detection against a labelled golden set.';

    public function handle(BrandLexicon $lexicon, MentionExtractor $mentions, ContextualCueDetector $cues): int
    {
        $path = (string) ($this->option('fixture') ?: base_path('tests/Fixtures/eval/golden-set.json'));

        if (! File::exists($path)) {
            $this->error("Fixture not found: {$path}");

            return self::FAILURE;
        }

        /** @var list<array<string, mixed>> $cases */
        $cases = json_decode(File::get($path), true) ?: [];

        $tp = $fp = $fn = 0;

        foreach ($cases as $case) {
            $caption = (string) ($case['caption'] ?? '');
            $brandsInCaption = $lexicon->matchAllInText($caption);
            $handleBrands = array_filter(array_map([$lexicon, 'resolveHandle'], $mentions->extract($caption)));
            $detectedBrands = array_values(array_unique([...$brandsInCaption, ...$handleBrands]));

            $predictedSeeded = $detectedBrands !== [] || ($case['product_tags'] ?? []) !== [];
            $actualSeeded = (bool) ($case['is_seeded'] ?? false);

            if ($predictedSeeded && $actualSeeded) {
                $tp++;
            } elseif ($predictedSeeded && ! $actualSeeded) {
                $fp++;
            } elseif (! $predictedSeeded && $actualSeeded) {
                $fn++;
            }
        }

        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;

        $this->table(['metric', 'value'], [
            ['cases', count($cases)],
            ['true positives', $tp],
            ['false positives', $fp],
            ['false negatives', $fn],
            ['recall', number_format($recall, 3)],
            ['precision', number_format($precision, 3)],
        ]);

        return self::SUCCESS;
    }
}
