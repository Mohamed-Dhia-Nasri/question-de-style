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
}
