<?php

namespace App\Platform\Ingestion\Normalization;

use App\Platform\Ingestion\Support\ErrorCategory;
use App\Shared\Enums\MetricTier;
use App\Shared\ValueObjects\MetricValue;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Typed field extraction for raw provider items. Every accessor either
 * returns a well-typed value or throws RecordRejected with the correct
 * normalized category (missing required field vs invalid field type) — the
 * validate-before-persist gate of the ingestion spec.
 */
final class Extract
{
    /**
     * First present, non-empty string among $keys; null when absent.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public static function string(array $raw, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $raw[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }

            if ($value !== null && ! is_string($value)) {
                throw new RecordRejected(
                    ErrorCategory::InvalidFieldTypes,
                    "Field [{$key}] must be a string.",
                );
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    public static function requireString(array $raw, string $context, string ...$keys): string
    {
        $value = self::string($raw, ...$keys);

        if ($value === null) {
            $list = implode('|', $keys);

            throw new RecordRejected(
                ErrorCategory::MissingRequiredFields,
                "{$context}: required field [{$list}] is missing or empty.",
                self::hint($raw),
            );
        }

        return $value;
    }

    /**
     * First present numeric value among $keys as int; null when absent.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public static function int(array $raw, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            $value = $raw[$key] ?? null;

            if ($value === null) {
                continue;
            }

            if (! is_numeric($value)) {
                throw new RecordRejected(
                    ErrorCategory::InvalidFieldTypes,
                    "Field [{$key}] must be numeric.",
                    self::hint($raw),
                );
            }

            return (int) $value;
        }

        return null;
    }

    /**
     * Parse the first present timestamp (ISO-8601 string or unix seconds).
     *
     * @param  array<array-key, mixed>  $raw
     */
    public static function timestamp(array $raw, string ...$keys): ?CarbonImmutable
    {
        foreach ($keys as $key) {
            $value = $raw[$key] ?? null;

            if ($value === null) {
                continue;
            }

            try {
                if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                    return CarbonImmutable::createFromTimestampUTC((int) $value);
                }

                if (is_string($value)) {
                    return CarbonImmutable::parse($value);
                }
            } catch (Throwable) {
                // fall through to the rejection below
            }

            throw new RecordRejected(
                ErrorCategory::InvalidFieldTypes,
                "Field [{$key}] is not a parseable timestamp.",
                self::hint($raw),
            );
        }

        return null;
    }

    /**
     * Build a labelled PUBLIC MetricValue, or null when the count is absent
     * (missing optional metric — never fabricate a zero).
     */
    public static function publicMetric(string $metric, ?int $amount): ?MetricValue
    {
        return $amount === null ? null : new MetricValue((float) $amount, MetricTier::Public, $metric);
    }

    /**
     * Best-effort external id for quarantine hints (not validation).
     *
     * @param  array<array-key, mixed>  $raw
     */
    public static function hint(array $raw): ?string
    {
        foreach (['id', 'shortCode', 'pk', 'videoId'] as $key) {
            $value = $raw[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function __construct() {}
}
