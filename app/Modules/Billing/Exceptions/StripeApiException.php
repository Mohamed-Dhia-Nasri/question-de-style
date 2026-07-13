<?php

namespace App\Modules\Billing\Exceptions;

use RuntimeException;

/**
 * A failed Stripe API call, sanitized (ADR-0021).
 *
 * The ProviderCallException doctrine applied to billing: the message
 * carries the operation and classification facts (HTTP status, Stripe
 * error code) — never the raw response body, request payload, or any
 * secret. Safe to log and to surface to an operator.
 */
class StripeApiException extends RuntimeException
{
    public function __construct(
        public readonly string $operation,
        string $sanitizedMessage,
        public readonly ?int $httpStatus = null,
        public readonly ?string $stripeErrorCode = null,
    ) {
        parent::__construct($sanitizedMessage);
    }

    public static function http(string $operation, int $status, ?string $errorCode): self
    {
        return new self(
            $operation,
            "Stripe {$operation} failed (HTTP {$status}".($errorCode !== null ? ", {$errorCode}" : '').')',
            $status,
            $errorCode,
        );
    }

    public static function network(string $operation): self
    {
        return new self($operation, "Stripe {$operation} failed (network/timeout)");
    }

    public static function malformed(string $operation): self
    {
        return new self($operation, "Stripe {$operation} returned an unexpected response shape");
    }

    public static function notConfigured(string $operation): self
    {
        return new self($operation, "Stripe {$operation} unavailable: no STRIPE_SECRET configured");
    }
}
