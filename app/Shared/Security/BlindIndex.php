<?php

namespace App\Shared\Security;

use RuntimeException;

/**
 * Keyed blind-index hashing for exact lookup over encrypted personal data.
 *
 * Encrypted columns (Laravel `encrypted` cast) cannot be queried. Where exact
 * lookup is required (e.g. find a creator by email), store — alongside the
 * encrypted value — a deterministic HMAC-SHA256 of the normalized plaintext
 * under a dedicated environment-managed key (QDS_BLIND_INDEX_KEY):
 *
 *   $table->string('email');            // encrypted cast (display/recovery)
 *   $table->string('email_index', 64);  // BlindIndex::hash($email), indexed
 *
 * A plain unsalted hash is forbidden for low-entropy personal data: without
 * the key, candidate values could be enumerated offline. The HMAC key never
 * lives in the database, repository, logs, fixtures, or frontend.
 *
 * Key separation from APP_KEY means either key can rotate independently; a
 * blind-index key rotation re-computes index columns from decrypted values
 * (no data loss — the encrypted column remains the source of truth).
 */
final class BlindIndex
{
    public static function hash(string $value): string
    {
        return hash_hmac('sha256', self::normalize($value), self::key());
    }

    /**
     * Normalization must match between write and lookup: trim + lowercase.
     * Suitable for emails/phone-digits; adjust per-field only with care.
     */
    public static function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private static function key(): string
    {
        $key = config('qds.security.blind_index_key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException(
                'QDS_BLIND_INDEX_KEY is not configured. Generate one with: '
                ."php -r \"echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;\""
            );
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded === false) {
                throw new RuntimeException('QDS_BLIND_INDEX_KEY is not valid base64.');
            }

            return $decoded;
        }

        return $key;
    }

    private function __construct() {}
}
