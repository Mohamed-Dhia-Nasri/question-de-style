<?php

namespace App\Platform\Enrichment\Reach;

use App\Shared\Enums\Platform;

/**
 * Validates a reach configuration before it may be saved or activated
 * (REQ-M1-006, ADR-0022 (pending)). The formula is a CONTROLLED MODEL:
 *   estimated_reach = round(view_weight*views + follower_weight*followers)
 * with optional per-platform weight overrides. No arbitrary expression is
 * ever parsed. Reach must be modeled from PUBLIC views AND a follower
 * signal — it is never a raw view count (GL-PublicViews / DEF-003), so
 * follower_weight must be strictly positive and a views-only passthrough
 * (view_weight >= 1 with follower_weight <= 0) is rejected.
 */
class ReachConfigurationValidator
{
    /** Allowed weight keys in `default` and per-platform overrides. */
    public const WEIGHT_KEYS = ['view_weight', 'follower_weight'];

    /**
     * @param  array<string, mixed>  $attributes  name, method, formula_version, effective_from, params
     * @return list<string> validation errors (empty = valid)
     */
    public function validate(array $attributes): array
    {
        $errors = [];

        foreach (['name', 'method', 'formula_version'] as $field) {
            if (! is_string($attributes[$field] ?? null) || trim((string) ($attributes[$field] ?? '')) === '') {
                $errors[] = "{$field} is required.";
            }
        }

        if (($attributes['effective_from'] ?? null) === null) {
            $errors[] = 'effective_from is required.';
        }

        return [...$errors, ...$this->validateParams($attributes['params'] ?? null)];
    }

    /** @return list<string> */
    private function validateParams(mixed $params): array
    {
        if (! is_array($params)) {
            return ['params is required and must be a structured object.'];
        }

        $errors = [];

        $unknown = array_diff(array_keys($params), [...self::WEIGHT_KEYS, 'platforms']);
        if ($unknown !== []) {
            $errors[] = 'params contains unsupported keys: '.implode(', ', $unknown).'.';
        }

        $viewWeight = $params['view_weight'] ?? null;
        $followerWeight = $params['follower_weight'] ?? null;

        if (! is_numeric($viewWeight) || (float) $viewWeight < 0) {
            $errors[] = 'params.view_weight must be a non-negative number.';
        }

        if (! is_numeric($followerWeight) || (float) $followerWeight <= 0) {
            $errors[] = 'params.follower_weight must be a positive number — reach must be modeled from a follower signal, never views alone (GL-PublicViews).';
        }

        if (is_numeric($viewWeight) && is_numeric($followerWeight)
            && (float) $viewWeight >= 1.0 && (float) $followerWeight <= 0.0) {
            $errors[] = 'params must not reduce to a raw view count (view_weight >= 1 with follower_weight <= 0). Reach is never a view count (DEF-003).';
        }

        return [...$errors, ...$this->validatePlatformOverrides($params['platforms'] ?? null, $viewWeight, $followerWeight)];
    }

    /** @return list<string> */
    private function validatePlatformOverrides(mixed $platforms, mixed $defaultViewWeight, mixed $defaultFollowerWeight): array
    {
        if ($platforms === null) {
            return [];
        }

        if (! is_array($platforms)) {
            return ['params.platforms must be an object keyed by ENUM-Platform values.'];
        }

        $allowedPlatforms = array_column(Platform::cases(), 'value');
        $errors = [];

        foreach ($platforms as $key => $weights) {
            if (! in_array($key, $allowedPlatforms, true)) {
                $errors[] = "params.platforms contains an unknown platform [{$key}].";

                continue;
            }

            if (! is_array($weights)) {
                $errors[] = "params.platforms.{$key} must be an object of weights.";

                continue;
            }

            $unknown = array_diff(array_keys($weights), self::WEIGHT_KEYS);
            if ($unknown !== []) {
                $errors[] = "params.platforms.{$key} contains unsupported keys: ".implode(', ', $unknown).'.';
            }

            foreach ($weights as $weightKey => $value) {
                if (in_array($weightKey, self::WEIGHT_KEYS, true) && (! is_numeric($value) || (float) $value < 0)) {
                    $errors[] = "params.platforms.{$key}.{$weightKey} must be a non-negative number.";
                }
            }

            $effectiveFollower = (array_key_exists('follower_weight', $weights) && is_numeric($weights['follower_weight']))
                ? (float) $weights['follower_weight']
                : (is_numeric($defaultFollowerWeight) ? (float) $defaultFollowerWeight : null);

            if ($effectiveFollower !== null && $effectiveFollower <= 0.0) {
                $errors[] = "params.platforms.{$key} reduces reach to a views-only estimate (effective follower_weight <= 0) — reach must retain a follower signal (GL-PublicViews / DEF-003).";
            }
        }

        return $errors;
    }
}
