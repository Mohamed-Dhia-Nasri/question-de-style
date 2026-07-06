<?php

namespace Tests\Unit\Enrichment;

use App\Platform\Enrichment\Emv\EmvConfigurationValidator;
use Tests\TestCase;

/**
 * REQ-M1-011 configuration validation: the EMV formula is a CONTROLLED
 * EXPRESSION MODEL — only the canonical MET-EMV structure
 * "Σ (metric_i × rate_i)" is accepted, no default rates are invented, and
 * every rate must attach to a known PUBLIC content metric that the formula
 * actually uses. The validator is pure: no DB involved.
 */
class EmvConfigurationValidatorTest extends TestCase
{
    private EmvConfigurationValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new EmvConfigurationValidator;
    }

    /** @return array<string, mixed> */
    private function validAttributes(): array
    {
        return [
            'name' => 'EMV model 2026-Q3',
            'formula_version' => 'formula-v1',
            'rate_card_version' => 'rates-v1',
            'currency' => 'EUR',
            'formula' => [
                'model' => EmvConfigurationValidator::MODEL_RATE_CARD_SUM,
                'metrics' => ['views', 'likes', 'comments'],
            ],
            'rates' => [
                'default' => ['views' => 0.01, 'likes' => 0.05, 'comments' => 0.2],
            ],
            'effective_from' => '2026-07-01',
        ];
    }

    /** @param list<string> $errors */
    private function assertHasErrorContaining(string $needle, array $errors): void
    {
        foreach ($errors as $error) {
            if (str_contains($error, $needle)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail("No validation error contains [{$needle}]. Got: ".implode(' | ', $errors));
    }

    public function test_a_fully_specified_configuration_is_valid(): void
    {
        $this->assertSame([], $this->validator->validate($this->validAttributes()));
    }

    public function test_name_and_versions_are_required(): void
    {
        $attributes = $this->validAttributes();
        unset($attributes['name'], $attributes['formula_version'], $attributes['rate_card_version']);

        $errors = $this->validator->validate($attributes);

        $this->assertContains('name is required.', $errors);
        $this->assertContains('formula_version is required.', $errors);
        $this->assertContains('rate_card_version is required.', $errors);
    }

    public function test_currency_must_be_a_three_letter_iso_code(): void
    {
        foreach (['EURO', 'eu'] as $badCurrency) {
            $attributes = $this->validAttributes();
            $attributes['currency'] = $badCurrency;

            $this->assertHasErrorContaining(
                'currency must be a 3-letter ISO code',
                $this->validator->validate($attributes),
            );
        }
    }

    public function test_arbitrary_formula_models_are_rejected(): void
    {
        // Controlled expression model only: no user expression is ever
        // parsed or executed.
        $attributes = $this->validAttributes();
        $attributes['formula']['model'] = 'custom_expression';

        $errors = $this->validator->validate($attributes);

        $this->assertHasErrorContaining('formula.model must be "rate_card_sum"', $errors);
        $this->assertHasErrorContaining('arbitrary expressions are rejected', $errors);
    }

    public function test_formula_metrics_must_be_a_non_empty_list(): void
    {
        $attributes = $this->validAttributes();
        $attributes['formula']['metrics'] = [];

        $this->assertHasErrorContaining(
            'formula.metrics must be a non-empty list',
            $this->validator->validate($attributes),
        );
    }

    public function test_unknown_formula_metrics_are_rejected(): void
    {
        $attributes = $this->validAttributes();
        $attributes['formula']['metrics'] = ['views', 'sales'];
        $attributes['rates']['default'] = ['views' => 0.01, 'sales' => 1.0];

        $this->assertHasErrorContaining(
            'unsupported metric: sales',
            $this->validator->validate($attributes),
        );
    }

    public function test_duplicate_formula_metrics_are_rejected(): void
    {
        $attributes = $this->validAttributes();
        $attributes['formula']['metrics'] = ['views', 'views', 'likes'];
        $attributes['rates']['default'] = ['views' => 0.01, 'likes' => 0.05];

        $this->assertHasErrorContaining(
            'formula.metrics must not contain duplicates',
            $this->validator->validate($attributes),
        );
    }

    public function test_a_default_rate_card_is_required(): void
    {
        // No built-in defaults exist and none are invented (REQ-M1-011).
        $attributes = $this->validAttributes();
        unset($attributes['rates']['default']);

        $this->assertHasErrorContaining(
            'rates.default is required',
            $this->validator->validate($attributes),
        );
    }

    public function test_every_formula_metric_needs_a_default_rate(): void
    {
        $attributes = $this->validAttributes();
        unset($attributes['rates']['default']['comments']);

        $this->assertHasErrorContaining(
            'rates.default is missing a rate for formula metric [comments]',
            $this->validator->validate($attributes),
        );
    }

    public function test_negative_rates_are_rejected(): void
    {
        $attributes = $this->validAttributes();
        $attributes['rates']['default']['views'] = -0.01;

        $this->assertHasErrorContaining(
            'rates.default.views must be a non-negative number',
            $this->validator->validate($attributes),
        );
    }

    public function test_country_rate_variations_are_unsupported_in_v1(): void
    {
        // Content carries no country attribution, so market variations
        // cannot be applied honestly.
        $attributes = $this->validAttributes();
        $attributes['rates']['countries'] = ['DE' => ['views' => 0.02]];

        $this->assertHasErrorContaining(
            'rates.countries is not supported in v1',
            $this->validator->validate($attributes),
        );
    }

    public function test_platform_overrides_must_use_known_platforms(): void
    {
        $attributes = $this->validAttributes();
        $attributes['rates']['platforms'] = ['SNAPCHAT' => ['views' => 0.02]];

        $this->assertHasErrorContaining(
            'rates.platforms contains an unknown key [SNAPCHAT]',
            $this->validator->validate($attributes),
        );
    }

    public function test_rates_for_metrics_outside_the_formula_are_rejected(): void
    {
        $attributes = $this->validAttributes();
        // 'shares' and 'saves' are allowed metric labels, but this formula
        // does not use them — a rate that can never apply is a lie in the
        // disclosure and is rejected.
        $attributes['rates']['default']['shares'] = 0.1;
        $attributes['rates']['platforms'] = ['INSTAGRAM' => ['saves' => 0.3]];

        $errors = $this->validator->validate($attributes);

        $this->assertHasErrorContaining(
            'rates.default contains a rate for [shares], which is not a formula metric',
            $errors,
        );
        $this->assertHasErrorContaining(
            'rates.platforms.INSTAGRAM contains a rate for [saves], which is not a formula metric',
            $errors,
        );
    }
}
