<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\Reach\ReachConfigurationValidator;
use Tests\TestCase;

/**
 * Reach formula settings: reach must be modeled from PUBLIC views AND a
 * follower signal — it is NEVER a raw view count (GL-PublicViews /
 * DEF-003). The validator is pure: no DB involved.
 */
class ReachConfigurationValidatorTest extends TestCase
{
    private ReachConfigurationValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new ReachConfigurationValidator;
    }

    /** @return array<string,mixed> */
    private function attrs(array $params = ['view_weight' => 0.7, 'follower_weight' => 0.1]): array
    {
        return ['name' => 'R', 'method' => 'qds-estimated-reach', 'formula_version' => 'reach-v1',
            'effective_from' => now(), 'params' => $params];
    }

    public function test_accepts_a_valid_config(): void
    {
        $this->assertSame([], $this->validator->validate($this->attrs()));
    }

    public function test_rejects_a_raw_view_count_passthrough(): void
    {
        $this->assertNotSame([], $this->validator->validate($this->attrs(['view_weight' => 1.0, 'follower_weight' => 0.0])));
    }

    public function test_requires_follower_weight_greater_than_zero(): void
    {
        $this->assertNotSame([], $this->validator->validate($this->attrs(['view_weight' => 0.7, 'follower_weight' => 0.0])));
    }

    public function test_rejects_negative_weights(): void
    {
        $this->assertNotSame([], $this->validator->validate($this->attrs(['view_weight' => -0.1, 'follower_weight' => 0.1])));
    }

    public function test_rejects_unknown_params_key(): void
    {
        $this->assertNotSame([], $this->validator->validate($this->attrs(['view_weight' => 0.7, 'follower_weight' => 0.1, 'nope' => 1])));
    }

    public function test_accepts_valid_platform_override(): void
    {
        $this->assertSame([], $this->validator->validate($this->attrs([
            'view_weight' => 0.7, 'follower_weight' => 0.1,
            'platforms' => ['INSTAGRAM' => ['view_weight' => 0.8, 'follower_weight' => 0.05]],
        ])));
    }

    public function test_rejects_unknown_platform_key(): void
    {
        $this->assertNotSame([], $this->validator->validate($this->attrs([
            'view_weight' => 0.7, 'follower_weight' => 0.1,
            'platforms' => ['MYSPACE' => ['view_weight' => 0.8]],
        ])));
    }

    public function test_rejects_platform_override_that_zeroes_follower_signal(): void
    {
        $this->assertNotSame([], $this->validator->validate($this->attrs([
            'view_weight' => 0.7, 'follower_weight' => 0.1,
            'platforms' => ['INSTAGRAM' => ['view_weight' => 2.0, 'follower_weight' => 0]],
        ])));
    }

    public function test_platform_override_inherits_valid_default_follower_weight(): void
    {
        $this->assertSame([], $this->validator->validate($this->attrs([
            'view_weight' => 0.7, 'follower_weight' => 0.1,
            'platforms' => ['INSTAGRAM' => ['view_weight' => 3.0]],
        ])));
    }

    public function test_requires_name(): void
    {
        $attrs = $this->attrs();
        $attrs['name'] = '';

        $this->assertNotSame([], $this->validator->validate($attrs));
    }
}
