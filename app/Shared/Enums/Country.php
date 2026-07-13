<?php

namespace App\Shared\Enums;

/**
 * Operating countries for country pickers and country display: the agency
 * works the DACH region plus France, so UI surfaces offer exactly these
 * and render full names, never ISO codes. Storage stays ISO-3166 alpha-2
 * (clients.country, geo_attributions.country_code) — this enum is the
 * presentation/validation boundary, not a schema change. Codes outside
 * the set (e.g. written later by Module 2 auto-inference) still display
 * via labelFor()'s raw-code fallback.
 */
enum Country: string
{
    case Germany = 'DE';
    case Austria = 'AT';
    case Switzerland = 'CH';
    case France = 'FR';

    /** Human-facing label (presentation only — same convention as RoleName). */
    public function label(): string
    {
        return match ($this) {
            self::Germany => 'Germany',
            self::Austria => 'Austria',
            self::Switzerland => 'Switzerland',
            self::France => 'France',
        };
    }

    /** Full name for a stored code; unknown/legacy codes fall back verbatim. */
    public static function labelFor(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::tryFrom(strtoupper($code))?->label() ?? $code;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
