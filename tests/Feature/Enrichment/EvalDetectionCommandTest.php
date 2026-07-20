<?php

namespace Tests\Feature\Enrichment;

use App\Modules\CRM\Models\Brand;
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

    public function test_vlm_cases_score_through_the_real_validator_and_band_mapper(): void
    {
        $path = base_path('tests/Fixtures/eval/vlm-tiny.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            [
                'platform' => 'INSTAGRAM', 'caption' => '', 'mentions' => [], 'is_seeded' => false,
                'vlm' => [
                    'candidates' => [['product' => 'Test Widget', 'brand' => 'Test Labs', 'category' => 'TECH']],
                    'frames' => [['name' => 'FRAME_1', 't_ms' => 1000], ['name' => 'FRAME_2', 't_ms' => 3000]],
                    'verdict_fixture' => [
                        'outcome' => 'PRODUCT_CONFIRMED',
                        'verdicts' => [[
                            'product_key' => 'P1', 'visible' => true, 'spoken' => false,
                            'gifting_cue' => false, 'confidence' => 0.91,
                            'frame_names' => ['FRAME_1'], 'rationale' => 'clearly on screen',
                        ]],
                    ],
                    'expected' => ['product' => 'Test Widget', 'band' => 'auto'],
                    'look_alike' => false,
                ],
            ],
            [
                'platform' => 'TIKTOK', 'caption' => '', 'mentions' => [], 'is_seeded' => false,
                'vlm' => [
                    'candidates' => [
                        ['product' => 'Test Widget', 'brand' => 'Test Labs', 'category' => 'TECH'],
                        ['product' => 'Other Gadget', 'brand' => 'Other Co', 'category' => 'BEAUTY'],
                    ],
                    'frames' => [['name' => 'FRAME_1', 't_ms' => 1000]],
                    'verdict_fixture' => [
                        'outcome' => 'PRODUCT_CONFIRMED',
                        // Exact-cover violation: P2 has no verdict — the real
                        // VerdictValidator must reject; eval must never score a product.
                        'verdicts' => [[
                            'product_key' => 'P1', 'visible' => true, 'spoken' => false,
                            'gifting_cue' => false, 'confidence' => 0.88,
                            'frame_names' => ['FRAME_1'], 'rationale' => 'covers only one candidate',
                        ]],
                    ],
                    'expected' => ['product' => null, 'band' => 'none'],
                    'look_alike' => false,
                ],
            ],
        ]));

        // Register in output order (see the Mockery line-claiming note above).
        $this->artisan('qds:eval-detection', ['--fixture' => $path])
            ->expectsOutputToContain('VLM grounding')
            ->expectsOutputToContain('vlm product recall')
            ->expectsOutputToContain('auto=1 none=1')     // band distribution
            ->expectsOutputToContain('validator rejects')
            ->expectsOutputToContain('$0.030000')          // 1 request/case × $0.030
            ->assertExitCode(0);

        File::delete($path);
    }

    public function test_speech_cases_mine_brands_through_the_lexicon_and_pick_the_dominant_language(): void
    {
        Brand::factory()->create(['name' => 'Velura Cosmetics', 'aliases' => []]);
        Brand::factory()->create(['name' => 'PureGlow Skin', 'aliases' => []]);

        $path = base_path('tests/Fixtures/eval/speech-tiny.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([[
            'platform' => 'INSTAGRAM', 'caption' => '', 'mentions' => [], 'is_seeded' => false,
            'speech' => [
                'chunks' => [
                    ['ordinal' => 0, 'offset_ms' => 0, 'duration_ms' => 55000, 'language' => 'de-DE',
                        'text' => 'heute zeige ich euch das neue Velura Cosmetics Serum'],
                    ['ordinal' => 1, 'offset_ms' => 55000, 'duration_ms' => 30000, 'language' => 'en-US',
                        'text' => 'and a quick look at the PureGlow Skin routine'],
                ],
                'expected' => ['brands' => ['Velura Cosmetics', 'PureGlow Skin'], 'dominant_language' => 'de-DE'],
            ],
        ]]));

        $this->artisan('qds:eval-detection', ['--fixture' => $path])
            ->expectsOutputToContain('Multilingual speech')
            ->expectsOutputToContain('2/2')                // spoken brands found
            ->expectsOutputToContain('1/1')                // dominant language as expected
            ->expectsOutputToContain('$0.032000')          // 2 chunks × $0.016
            ->assertExitCode(0);

        File::delete($path);
    }

    public function test_bundled_golden_set_scores_vlm_and_speech_sections(): void
    {
        $this->artisan('qds:eval-detection')
            ->expectsOutputToContain('VLM grounding')
            ->expectsOutputToContain('look-alike disambiguation')
            ->expectsOutputToContain('Multilingual speech')
            ->assertExitCode(0);
    }
}
