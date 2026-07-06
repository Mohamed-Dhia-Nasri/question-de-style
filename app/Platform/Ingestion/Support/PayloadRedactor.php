<?php

namespace App\Platform\Ingestion\Support;

/**
 * Redacts provider payloads and error messages before anything is stored or
 * logged: no credentials, no personal contact data, no full media, no
 * private/signed URLs (Security requirements of the ingestion spec; DP-005).
 *
 * Redaction is deny-list based on key names plus pattern scrubbing on string
 * values. It is intentionally aggressive — a lost debug field is cheaper
 * than a leaked token.
 */
final class PayloadRedactor
{
    /** Keys whose values are always replaced, at any depth (lowercase match). */
    private const REDACTED_KEYS = [
        'token', 'api_key', 'apikey', 'key', 'secret', 'password', 'authorization',
        'auth', 'cookie', 'cookies', 'session', 'sessionid', 'csrf',
        'email', 'phone', 'phonenumber', 'address', 'signedurl', 'signed_url',
    ];

    /** Keys that carry media/blob payloads — dropped, never stored. */
    private const DROPPED_KEYS = [
        'imagebase64', 'videobase64', 'base64', 'binary', 'blob', 'buffer',
    ];

    private const REPLACEMENT = '[REDACTED]';

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function redact(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            $lower = is_string($key) ? strtolower(str_replace(['-', '_'], '', $key)) : '';

            if (in_array($lower, array_map(fn (string $k): string => str_replace('_', '', $k), self::DROPPED_KEYS), true)) {
                continue;
            }

            if (in_array($lower, array_map(fn (string $k): string => str_replace('_', '', $k), self::REDACTED_KEYS), true)) {
                $clean[$key] = self::REPLACEMENT;

                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => $this->redact($value),
                is_string($value) => $this->redactString($value),
                default => $value,
            };
        }

        return $clean;
    }

    /**
     * Scrub secrets and query-string credentials out of a free-form string
     * (URLs with ?token=…, bearer headers, emails).
     */
    public function redactString(string $value): string
    {
        // Bearer / token fragments.
        $value = preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer '.self::REPLACEMENT, $value) ?? $value;

        // Credential-bearing query parameters (token, key, signature, …).
        $value = preg_replace(
            '/([?&](?:token|key|api_key|apikey|signature|sig|x-amz-[a-z-]+|expires)=)[^&\s"\']*/i',
            '$1'.self::REPLACEMENT,
            $value
        ) ?? $value;

        // Email addresses (personal data, DP-005).
        $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', self::REPLACEMENT, $value) ?? $value;

        return $value;
    }
}
