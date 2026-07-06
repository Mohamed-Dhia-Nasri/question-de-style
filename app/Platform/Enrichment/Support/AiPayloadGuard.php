<?php

namespace App\Platform\Enrichment\Support;

use InvalidArgumentException;

/**
 * Outbound guard for every payload sent to an external AI provider
 * (DP-005; DEVELOPMENT.md non-negotiable: "Personal data never appears in
 * ... external AI requests — use internal identifiers").
 *
 * Blocks payloads containing:
 *  - personal/credential field names (email, phone, address, recipient,
 *    token, secret, ... at any depth);
 *  - email addresses or credential/signature query parameters inside
 *    string values (a signed private URL must never leave the platform);
 *  - free-text notes fields (private CRM/campaign notes are never AI input).
 *
 * The guard REJECTS (throws) rather than redacts: an enrichment payload is
 * built from allowlisted public fields, so a hit means a programming error
 * upstream — failing loudly beats silently shipping a stripped payload.
 */
final class AiPayloadGuard
{
    private const FORBIDDEN_KEYS = [
        'email', 'phone', 'phonenumber', 'address', 'postaladdress',
        'recipient', 'recipientname', 'shippingaddress', 'contact',
        'notes', 'privatenotes', 'internalnotes',
        'token', 'apikey', 'secret', 'password', 'authorization', 'auth',
        'cookie', 'session', 'signedurl', 'signed_url', 'credentials',
    ];

    private const STRING_PATTERNS = [
        // Email addresses.
        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
        // Credential / signature query parameters (incl. signed URLs).
        '/[?&](token|key|api_key|apikey|signature|sig|x-amz-[a-z-]+|expires)=/i',
        // Bearer tokens.
        '/Bearer\s+[A-Za-z0-9._\-]+/i',
    ];

    /**
     * @param  array<array-key, mixed>  $payload
     *
     * @throws InvalidArgumentException when the payload contains data that
     *                                  must never reach an AI provider
     */
    public static function assertSafe(array $payload): void
    {
        self::walk($payload, '$');
    }

    /** @param array<array-key, mixed> $data */
    private static function walk(array $data, string $path): void
    {
        foreach ($data as $key => $value) {
            $keyPath = $path.'.'.$key;

            if (is_string($key)) {
                $bare = str_replace(['-', '_'], '', mb_strtolower($key));

                if (in_array($bare, self::FORBIDDEN_KEYS, true)) {
                    throw new InvalidArgumentException(
                        "AI payload rejected: forbidden field [{$keyPath}] must never be sent to an external AI provider (DP-005)."
                    );
                }
            }

            if (is_array($value)) {
                self::walk($value, $keyPath);

                continue;
            }

            if (is_string($value)) {
                foreach (self::STRING_PATTERNS as $pattern) {
                    if (preg_match($pattern, $value) === 1) {
                        throw new InvalidArgumentException(
                            "AI payload rejected: value at [{$keyPath}] matches a personal-data/credential pattern (DP-005)."
                        );
                    }
                }
            }
        }
    }

    private function __construct() {}
}
