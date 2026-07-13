<?php

namespace App\Modules\Billing\Http;

/**
 * Stripe-Signature verification (ADR-0021) — pure, offline HMAC math.
 *
 * Stripe signs "{timestamp}.{raw body}" with the endpoint's webhook secret
 * (HMAC-SHA256) and sends "t=<ts>,v1=<sig>[,v1=<sig>…]" — multiple v1
 * entries appear during secret rotation. Verification is constant-time
 * per candidate and bounded by a timestamp tolerance (replay window).
 * Deliberately SDK-free so the test suite can mint valid and invalid
 * signatures locally without any Stripe dependency.
 */
class StripeWebhookSignature
{
    public function verify(string $payload, ?string $header, string $secret, int $toleranceSeconds): bool
    {
        if ($header === null || $header === '' || $secret === '') {
            return false;
        }

        $timestamp = null;
        $candidates = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 't' && $value !== null && ctype_digit($value)) {
                $timestamp = (int) $value;
            }

            if ($key === 'v1' && $value !== null && $value !== '') {
                $candidates[] = $value;
            }
        }

        if ($timestamp === null || $candidates === []) {
            return false;
        }

        // Replay window: reject signatures older (or claiming to be newer)
        // than the tolerance.
        if (abs(now()->getTimestamp() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($candidates as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
