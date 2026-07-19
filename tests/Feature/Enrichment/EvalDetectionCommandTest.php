<?php

namespace Tests\Feature\Enrichment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EvalDetectionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_eval_command_prints_metrics_and_exits_zero(): void
    {
        $path = base_path('tests/Fixtures/eval/tiny.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([[
            'platform' => 'INSTAGRAM', 'caption' => 'thanks @glossier for the You Perfume',
            'mentions' => ['glossier'], 'is_seeded' => true, 'brand' => 'Glossier', 'product' => 'You Perfume',
        ]]));

        $this->artisan('qds:eval-detection', ['--fixture' => $path])
            ->expectsOutputToContain('recall')
            ->assertExitCode(0);

        File::delete($path);
    }

    public function test_visual_cases_score_product_level_metrics_through_the_real_band_mapper(): void
    {
        $path = base_path('tests/Fixtures/eval/visual-tiny.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            [
                'platform' => 'INSTAGRAM', 'caption' => '', 'mentions' => [], 'is_seeded' => false,
                'visual' => [
                    'candidates' => [[
                        'product' => 'Test Widget', 'brand' => 'Test Labs', 'category' => 'TECH',
                        'photo_vectors' => [[1, 0, 0]],
                        'source' => 'shipment', 'shipment_in_window' => true,
                    ]],
                    'frame_vectors' => [
                        ['t_ms' => 0, 'vec' => [1, 0, 0]],
                        ['t_ms' => 2000, 'vec' => [1, 0, 0]],
                    ],
                    'expected' => ['product' => 'Test Widget', 'band' => 'auto'],
                    'brief_appearance' => false,
                ],
            ],
            [
                'platform' => 'TIKTOK', 'caption' => '', 'mentions' => [], 'is_seeded' => false,
                'visual' => [
                    'candidates' => [[
                        'product' => 'Other Gadget', 'brand' => 'Other Co', 'category' => 'BEAUTY',
                        'photo_vectors' => [[0, 1, 0]],
                        'source' => 'roster', 'shipment_in_window' => false,
                    ]],
                    'frame_vectors' => [
                        ['t_ms' => 0, 'vec' => [0, 1, 0]],
                        ['t_ms' => 2000, 'vec' => [0, 1, 0]],
                    ],
                    // The label says nothing should match — the confident
                    // AUTO hit is a labelled false positive (BEAUTY).
                    'expected' => ['product' => null, 'band' => 'none'],
                    'brief_appearance' => false,
                ],
            ],
        ]));

        $this->artisan('qds:eval-detection', ['--fixture' => $path])
            ->expectsOutputToContain('product recall')
            ->expectsOutputToContain('0.500')       // product precision: 1 TP / (1 TP + 1 FP)
            ->expectsOutputToContain('BEAUTY=1')    // false positives by category
            ->expectsOutputToContain('auto=2')      // band distribution
            ->expectsOutputToContain('1/2')         // bands as expected
            ->expectsOutputToContain('$0.000240')   // 2 billable frames × $0.00012 per case
            ->assertExitCode(0);

        File::delete($path);
    }

    public function test_degraded_coverage_reports_inconclusive_and_missed_brief_appearance(): void
    {
        $path = base_path('tests/Fixtures/eval/visual-inconclusive.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([[
            'platform' => 'INSTAGRAM', 'caption' => '', 'mentions' => [], 'is_seeded' => false,
            'visual' => [
                'candidates' => [[
                    'product' => 'Ghost Lamp', 'category' => 'HOME_INTERIOR',
                    'photo_vectors' => [[1, 0, 0]],
                    'source' => 'shipment', 'shipment_in_window' => true,
                ]],
                'frame_vectors' => [],
                'frames_skipped_quality' => 2,
                'expected' => ['product' => null, 'band' => 'none'],
                'brief_appearance' => true,
            ],
        ]]));

        $this->artisan('qds:eval-detection', ['--fixture' => $path])
            ->expectsOutputToContain('inconclusive=1')       // NOT no_match: coverage was degraded (§8 split)
            ->expectsOutputToContain('1 of 1 brief case(s)') // the brief appearance was missed
            ->assertExitCode(0);

        File::delete($path);
    }

    public function test_fixtures_without_visual_blocks_print_no_visual_section(): void
    {
        $path = base_path('tests/Fixtures/eval/text-only.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([[
            'platform' => 'INSTAGRAM', 'caption' => 'plain day', 'mentions' => [], 'is_seeded' => false,
        ]]));

        $this->artisan('qds:eval-detection', ['--fixture' => $path])
            ->expectsOutputToContain('recall')
            ->doesntExpectOutputToContain('Visual product matching')
            ->assertExitCode(0);

        File::delete($path);
    }

    public function test_bundled_golden_set_scores_text_and_visual_sections(): void
    {
        // Note: 'product recall' must be asserted before the plain 'recall'
        // substring — Laravel's expectsOutputToContain() registers Mockery
        // expectations in call order and the first one whose substring
        // matches a written line "claims" it, so a shorter earlier pattern
        // ('recall') would silently swallow the later, more specific line
        // ('product recall') that also contains it.
        $this->artisan('qds:eval-detection')
            ->expectsOutputToContain('product recall')
            ->expectsOutputToContain('band distribution')
            ->expectsOutputToContain('recall')
            ->assertExitCode(0);
    }
}
