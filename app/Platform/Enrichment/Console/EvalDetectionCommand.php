<?php

namespace App\Platform\Enrichment\Console;

use App\Modules\Monitoring\Models\Keyframe;
use App\Platform\AiBudget\Priority;
use App\Platform\Enrichment\Recognition\BrandLexicon;
use App\Platform\Enrichment\TextSignals\ContextualCueDetector;
use App\Platform\Enrichment\TextSignals\MentionExtractor;
use App\Platform\Enrichment\VisualMatch\Candidates\Candidate;
use App\Platform\Enrichment\VisualMatch\Candidates\CandidateSet;
use App\Platform\Enrichment\VisualMatch\Frames\FramePreparationResult;
use App\Platform\Enrichment\VisualMatch\Frames\PreparedFrame;
use App\Platform\Enrichment\VisualMatch\Matching\BandMapper;
use App\Platform\Enrichment\VisualMatch\Matching\CandidateScores;
use App\Platform\Enrichment\VisualMatch\Matching\FrameScore;
use App\Shared\Enums\SectorLabel;
use App\Shared\Enums\VisualMatchBand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Offline scorecard for seeded detection over a labelled golden set. Runs
 * the deterministic text-signal + classification path (no provider calls)
 * and prints recall/precision at brand and product level, per platform.
 * Cases may additionally carry a `visual` block (sub-project C, spec §15):
 * fixture photo/frame vectors scored through the REAL BandMapper — cosine
 * computed in PHP, dimension-agnostic, no DB and no network — reporting
 * product-level precision/recall, false positives by category, band
 * distribution, missed brief appearances, average margin, frame-skip
 * rate, and estimated embedding cost per case. The measurement baseline
 * for sub-projects D–E.
 */
class EvalDetectionCommand extends Command
{
    protected $signature = 'qds:eval-detection {--fixture=}';

    protected $description = 'Score seeded-product detection against a labelled golden set.';

    public function handle(BrandLexicon $lexicon, MentionExtractor $mentions, ContextualCueDetector $cues, BandMapper $bandMapper): int
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

        $this->scoreVisualCases($cases, $bandMapper);

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $cases */
    private function scoreVisualCases(array $cases, BandMapper $bandMapper): void
    {
        $visualCases = array_values(array_filter($cases, static fn (array $case): bool => isset($case['visual'])));

        if ($visualCases === []) {
            return;
        }

        $tp = $fp = $fn = 0;
        $bandsAsExpected = $missedBrief = $briefCases = 0;
        $skippedFrames = $availableFrames = $billableFrames = 0;
        /** @var array<string, int> $fpByCategory */
        $fpByCategory = [];
        /** @var array<string, int> $bandDistribution */
        $bandDistribution = [];
        /** @var list<float> $margins */
        $margins = [];

        foreach ($visualCases as $case) {
            /** @var array<string, mixed> $visual */
            $visual = $case['visual'];
            $frameVectors = array_values((array) ($visual['frame_vectors'] ?? []));
            $skippedFormat = (int) ($visual['frames_skipped_format'] ?? 0);
            $skippedQuality = (int) ($visual['frames_skipped_quality'] ?? 0);

            $prep = $this->prepFromFixture($frameVectors, $skippedFormat, $skippedQuality);
            [$candidates, $scored] = $this->scoreFixtureCandidates((array) ($visual['candidates'] ?? []), $frameVectors);

            $results = $bandMapper->map($scored, $prep);
            $outcome = $bandMapper->runOutcome($results, $prep, new CandidateSet($candidates, Priority::High));

            $top = null;

            foreach ($results as $result) {
                if ($result->band !== VisualMatchBand::Reject) {
                    $top = $result; // ranked best-first: first non-reject wins

                    break;
                }
            }

            $expected = (array) ($visual['expected'] ?? []);
            $expectedProduct = $expected['product'] ?? null;
            $expectedBand = (string) ($expected['band'] ?? 'none');
            $predictedProduct = $top?->candidate->productLabel;
            $predictedBand = $top?->band->value ?? 'none';

            if ($predictedProduct !== null && $predictedProduct === $expectedProduct) {
                $tp++;
            } elseif ($predictedProduct !== null) {
                $fp++;
                $category = $top->candidate->category?->value ?? 'default';
                $fpByCategory[$category] = ($fpByCategory[$category] ?? 0) + 1;

                if ($expectedProduct !== null) {
                    $fn++; // the WRONG product: both a false positive and a miss
                }
            } elseif ($expectedProduct !== null) {
                $fn++;
            }

            $bandKey = $top !== null ? $predictedBand : $outcome->value;
            $bandDistribution[$bandKey] = ($bandDistribution[$bandKey] ?? 0) + 1;

            if ($predictedBand === $expectedBand) {
                $bandsAsExpected++;
            }

            if ((bool) ($visual['brief_appearance'] ?? false)) {
                $briefCases++;

                if ($top === null) {
                    $missedBrief++;
                }
            }

            if ($top !== null && $top->marginToRunnerUp !== null) {
                $margins[] = $top->marginToRunnerUp;
            }

            $skippedFrames += $skippedFormat + $skippedQuality;
            $availableFrames += $prep->framesAvailable;
            $billableFrames += count($frameVectors);
        }

        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        ksort($fpByCategory);
        ksort($bandDistribution);

        $priceMicroUsd = (int) config('qds.ai_budget.capabilities.embedding.price_micro_usd_per_unit');
        $costPerCaseUsd = $billableFrames * $priceMicroUsd / count($visualCases) / 1_000_000;

        $this->newLine();
        $this->info('Visual product matching (real BandMapper over fixture vectors):');
        $this->table(['visual metric', 'value'], [
            ['visual cases', count($visualCases)],
            ['product true positives', $tp],
            ['product false positives', $fp],
            ['product false negatives', $fn],
            ['product recall', number_format($recall, 3)],
            ['product precision', number_format($precision, 3)],
            ['false positives by category', $fpByCategory === [] ? 'none' : $this->formatCounts($fpByCategory)],
            ['band distribution', $this->formatCounts($bandDistribution)],
            ['bands as expected', $bandsAsExpected.'/'.count($visualCases)],
            ['missed brief appearances', sprintf('%d of %d brief case(s)', $missedBrief, $briefCases)],
            ['avg margin (top candidate)', $margins === [] ? 'n/a' : number_format(array_sum($margins) / count($margins), 3)],
            ['frame skip rate', $availableFrames > 0 ? number_format($skippedFrames / $availableFrames, 3) : 'n/a'],
            ['est. embedding cost / case', '$'.number_format($costPerCaseUsd, 6)],
        ]);
    }

    /**
     * @param  list<array{t_ms?: int|null, vec: list<float|int>, represented_frames?: int}>  $frameVectors
     */
    private function prepFromFixture(array $frameVectors, int $skippedFormat, int $skippedQuality): FramePreparationResult
    {
        $frames = [];

        foreach ($frameVectors as $index => $frame) {
            $keyframe = new Keyframe(['ordinal' => $index, 'timestamp_ms' => $frame['t_ms'] ?? null]);
            $keyframe->id = $index + 1; // synthetic, never persisted — eval needs no DB

            $frames[] = new PreparedFrame(
                keyframe: $keyframe,
                bytes: '',
                mimeType: 'image/jpeg',
                representedFrames: (int) ($frame['represented_frames'] ?? 1),
                spanStartMs: $frame['t_ms'] ?? null,
                spanEndMs: $frame['t_ms'] ?? null,
            );
        }

        return new FramePreparationResult(
            frames: $frames,
            framesAvailable: count($frameVectors) + $skippedFormat + $skippedQuality,
            skippedFormat: $skippedFormat,
            skippedQuality: $skippedQuality,
            deduped: 0,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $candidateSpecs
     * @param  list<array{t_ms?: int|null, vec: list<float|int>, represented_frames?: int}>  $frameVectors
     * @return array{0: list<Candidate>, 1: list<CandidateScores>}
     */
    private function scoreFixtureCandidates(array $candidateSpecs, array $frameVectors): array
    {
        $candidates = [];
        $scored = [];

        foreach (array_values($candidateSpecs) as $index => $spec) {
            $candidate = new Candidate(
                productId: $index + 1, // synthetic, stable within the case (tie-breaks stay deterministic)
                productLabel: (string) $spec['product'],
                brandName: (string) ($spec['brand'] ?? $spec['product']),
                category: isset($spec['category']) ? SectorLabel::from((string) $spec['category']) : null,
                source: (string) ($spec['source'] ?? 'shipment'),
                shipmentInWindow: (bool) ($spec['shipment_in_window'] ?? false),
                seedingCampaignId: null,
                shipmentAnchorAt: null,
                shipmentAgeDays: null,
                hasEmbeddedPhotos: true,
            );
            $candidates[] = $candidate;

            $frameScores = [];

            foreach ($frameVectors as $frameIndex => $frame) {
                $best = 0.0;
                $bestPhoto = 1;

                foreach (array_values((array) $spec['photo_vectors']) as $photoIndex => $photoVector) {
                    $similarity = $this->cosine((array) $frame['vec'], (array) $photoVector);

                    if ($photoIndex === 0 || $similarity > $best) {
                        $best = $similarity;
                        $bestPhoto = $photoIndex + 1;
                    }
                }

                $frameScores[] = new FrameScore(
                    keyframeId: $frameIndex + 1,
                    ordinal: $frameIndex,
                    timestampMs: $frame['t_ms'] ?? null,
                    similarity: $best,
                    photoId: $bestPhoto,
                    representedFrames: (int) ($frame['represented_frames'] ?? 1),
                );
            }

            $scored[] = new CandidateScores($candidate, $frameScores);
        }

        return [$candidates, $scored];
    }

    /**
     * Plain cosine similarity over fixture vectors — dimension-agnostic on
     * purpose so fixtures stay small. Production similarity lives in SQL
     * (FrameProductScorer); this mirrors pgvector's 1 - cosine distance.
     *
     * @param  list<float|int>  $a
     * @param  list<float|int>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = $normA = $normB = 0.0;

        foreach ($a as $index => $value) {
            $dot += (float) $value * (float) ($b[$index] ?? 0.0);
            $normA += (float) $value ** 2;
        }

        foreach ($b as $value) {
            $normB += (float) $value ** 2;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /** @param array<string, int> $counts */
    private function formatCounts(array $counts): string
    {
        return implode(' ', array_map(
            static fn (string $key, int $count): string => "{$key}={$count}",
            array_keys($counts),
            array_values($counts),
        ));
    }
}
