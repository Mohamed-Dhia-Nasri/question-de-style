<?php

namespace App\Platform\Enrichment\Emv;

use App\Shared\Enums\ContentType;
use App\Shared\Enums\Platform;

/**
 * Validates an EMV configuration before it may be saved or activated
 * (REQ-M1-011). The formula is a CONTROLLED EXPRESSION MODEL — exactly the
 * canonical MET-EMV structure "Σ (metric_i × rate_i)" with a configurable
 * metric set and rate card. No arbitrary user expression is ever parsed or
 * executed; anything but the canonical structure is rejected as
 * unsupported.
 *
 * No default rates exist and none are invented: a configuration is
 * operator-authored in full, and EMV stays unavailable until a valid one
 * is activated.
 */
class EmvConfigurationValidator
{
    /** The single supported formula model (MET-EMV). */
    public const MODEL_RATE_CARD_SUM = 'rate_card_sum';

    /** PUBLIC content-metric labels a rate may be attached to. */
    public const ALLOWED_METRICS = ['views', 'plays', 'likes', 'comments', 'shares', 'saves'];

    /**
     * @param  array<string, mixed>  $attributes  name, formula_version,
     *                                            rate_card_version, currency,
     *                                            formula, rates, effective_from
     * @return list<string> validation errors (empty = valid)
     */
    public function validate(array $attributes): array
    {
        $errors = [];

        foreach (['name', 'formula_version', 'rate_card_version'] as $field) {
            if (! is_string($attributes[$field] ?? null) || trim((string) $attributes[$field]) === '') {
                $errors[] = "{$field} is required.";
            }
        }

        $currency = $attributes['currency'] ?? null;

        if (! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $errors[] = 'currency must be a 3-letter ISO code (e.g. EUR).';
        }

        if (($attributes['effective_from'] ?? null) === null) {
            $errors[] = 'effective_from is required.';
        }

        $formulaErrors = $this->validateFormula($attributes['formula'] ?? null);
        $errors = [...$errors, ...$formulaErrors];

        if ($formulaErrors === []) {
            /** @var array{model: string, metrics: list<string>} $formula */
            $formula = $attributes['formula'];
            $errors = [...$errors, ...$this->validateRates($attributes['rates'] ?? null, $formula['metrics'])];
        }

        return $errors;
    }

    /** @return list<string> */
    private function validateFormula(mixed $formula): array
    {
        if (! is_array($formula)) {
            return ['formula is required and must be a structured object.'];
        }

        $errors = [];

        $unknownKeys = array_diff(array_keys($formula), ['model', 'metrics']);

        if ($unknownKeys !== []) {
            $errors[] = 'formula contains unsupported keys: '.implode(', ', $unknownKeys).'.';
        }

        if (($formula['model'] ?? null) !== self::MODEL_RATE_CARD_SUM) {
            $errors[] = 'formula.model must be "'.self::MODEL_RATE_CARD_SUM.'" — the canonical MET-EMV structure (Σ metric_i × rate_i) is the only supported model; arbitrary expressions are rejected.';
        }

        $metrics = $formula['metrics'] ?? null;

        if (! is_array($metrics) || $metrics === [] || ! array_is_list($metrics)) {
            $errors[] = 'formula.metrics must be a non-empty list of metric labels.';

            return $errors;
        }

        foreach ($metrics as $metric) {
            if (! is_string($metric) || ! in_array($metric, self::ALLOWED_METRICS, true)) {
                $errors[] = 'formula.metrics contains an unsupported metric: '.(is_string($metric) ? $metric : gettype($metric)).' (allowed: '.implode(', ', self::ALLOWED_METRICS).').';
            }
        }

        if (count($metrics) !== count(array_unique(array_filter($metrics, 'is_string')))) {
            $errors[] = 'formula.metrics must not contain duplicates.';
        }

        return $errors;
    }

    /**
     * @param  list<string>  $formulaMetrics
     * @return list<string>
     */
    private function validateRates(mixed $rates, array $formulaMetrics): array
    {
        if (! is_array($rates)) {
            return ['rates is required and must be a structured rate card.'];
        }

        $errors = [];

        if (isset($rates['countries'])) {
            $errors[] = 'rates.countries is not supported in v1: content carries no country attribution, so market variations cannot be applied honestly. Remove it.';
        }

        $unknownKeys = array_diff(array_keys($rates), ['default', 'platforms', 'content_types', 'countries']);

        if ($unknownKeys !== []) {
            $errors[] = 'rates contains unsupported keys: '.implode(', ', $unknownKeys).'.';
        }

        $default = $rates['default'] ?? null;

        if (! is_array($default)) {
            $errors[] = 'rates.default is required: every formula metric needs a base rate (no built-in defaults exist).';
        } else {
            foreach ($formulaMetrics as $metric) {
                if (! isset($default[$metric])) {
                    $errors[] = "rates.default is missing a rate for formula metric [{$metric}].";
                } elseif (! is_numeric($default[$metric]) || (float) $default[$metric] < 0) {
                    $errors[] = "rates.default.{$metric} must be a non-negative number.";
                }
            }

            $errors = [...$errors, ...$this->unknownMetricErrors('rates.default', $default, $formulaMetrics)];
        }

        foreach (['platforms' => array_column(Platform::cases(), 'value'), 'content_types' => array_column(ContentType::cases(), 'value')] as $section => $allowedKeys) {
            $overrides = $rates[$section] ?? null;

            if ($overrides === null) {
                continue;
            }

            if (! is_array($overrides)) {
                $errors[] = "rates.{$section} must be an object keyed by ".($section === 'platforms' ? 'ENUM-Platform' : 'ENUM-ContentType').' values.';

                continue;
            }

            foreach ($overrides as $key => $metricRates) {
                if (! in_array($key, $allowedKeys, true)) {
                    $errors[] = "rates.{$section} contains an unknown key [{$key}].";

                    continue;
                }

                if (! is_array($metricRates)) {
                    $errors[] = "rates.{$section}.{$key} must be an object of metric rates.";

                    continue;
                }

                foreach ($metricRates as $metric => $rate) {
                    if (! in_array($metric, $formulaMetrics, true)) {
                        $errors[] = "rates.{$section}.{$key} contains a rate for [{$metric}], which is not a formula metric.";
                    } elseif (! is_numeric($rate) || (float) $rate < 0) {
                        $errors[] = "rates.{$section}.{$key}.{$metric} must be a non-negative number.";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $section
     * @param  list<string>  $formulaMetrics
     * @return list<string>
     */
    private function unknownMetricErrors(string $label, array $section, array $formulaMetrics): array
    {
        $errors = [];

        foreach (array_keys($section) as $metric) {
            if (! in_array($metric, $formulaMetrics, true)) {
                $errors[] = "{$label} contains a rate for [{$metric}], which is not a formula metric.";
            }
        }

        return $errors;
    }
}
