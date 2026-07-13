<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Stripe billing test helpers (ADR-0021) — the FakesProviderResponses
 * pattern: fake credentials via config, drive the REAL signature math
 * locally (HMAC is deterministic), never touch the network.
 */
trait InteractsWithStripe
{
    protected function fakeStripeCredentials(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_qds',
            'services.stripe.webhook_secret' => 'whsec_test_qds',
        ]);
    }

    /** A VALID Stripe-Signature header for the given payload. */
    protected function stripeSignature(string $payload, ?int $timestamp = null, ?string $secret = null): string
    {
        $timestamp ??= now()->getTimestamp();
        $secret ??= (string) config('services.stripe.webhook_secret');

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    /** POST a signed event to the webhook endpoint. */
    protected function postStripeWebhook(array $event, ?string $signature = null): TestResponse
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);

        return $this->call(
            'POST',
            '/webhooks/stripe',
            [],
            [],
            [],
            [
                'HTTP_STRIPE_SIGNATURE' => $signature ?? $this->stripeSignature($payload),
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload,
        );
    }

    /**
     * A Stripe event envelope.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    protected function stripeEvent(string $type, array $object, ?string $eventId = null, ?int $created = null): array
    {
        return [
            'id' => $eventId ?? 'evt_'.fake()->unique()->lexify('????????????'),
            'object' => 'event',
            'type' => $type,
            'created' => $created ?? now()->getTimestamp(),
            'data' => ['object' => $object],
        ];
    }

    /**
     * A Stripe subscription object as it appears in webhook payloads.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function stripeSubscriptionObject(
        string $subscriptionId,
        string $customerId,
        string $priceId,
        string $status = 'active',
        array $overrides = [],
    ): array {
        return array_merge([
            'id' => $subscriptionId,
            'object' => 'subscription',
            'customer' => $customerId,
            'status' => $status,
            'cancel_at_period_end' => false,
            'current_period_end' => now()->addMonth()->getTimestamp(),
            'trial_end' => null,
            'ended_at' => null,
            'items' => ['data' => [['price' => ['id' => $priceId]]]],
        ], $overrides);
    }
}
