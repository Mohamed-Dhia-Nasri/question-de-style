<?php

namespace App\Modules\Billing\Http;

use App\Models\Tenant;
use App\Modules\Billing\Exceptions\StripeApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin Stripe REST client (ADR-0021) — the house integration doctrine
 * (ApifyClient/GoogleApiClient): a concrete class on the Http facade, no
 * SDK, secret ONLY from config and ONLY in the Authorization header, every
 * failure rethrown as a sanitized StripeApiException. Bodies are
 * form-encoded (Stripe's wire format); nested params use PHP array syntax,
 * which http_build_query renders exactly as Stripe expects.
 *
 * Deliberately minimal surface: QDS delegates every card-touching and
 * plan-mutating interaction to Stripe-hosted pages (Checkout + Billing
 * Portal) and receives state back through verified webhooks — these five
 * calls are the entire outbound integration.
 */
class StripeClient
{
    public function isConfigured(): bool
    {
        return (string) config('services.stripe.secret') !== '';
    }

    /**
     * Create the tenant's Stripe customer. Idempotent at the Stripe side:
     * the deterministic Idempotency-Key means a retried call (or a racing
     * duplicate) returns the SAME customer instead of minting a second one.
     */
    public function createCustomer(Tenant $tenant): string
    {
        $response = $this->send('create customer', fn (PendingRequest $request): Response => $request
            ->withHeaders(['Idempotency-Key' => 'qds-tenant-'.$tenant->id.'-customer'])
            ->post('/customers', [
                'name' => $tenant->name,
                // Reconciliation aid ONLY — webhooks never trust metadata
                // for tenant resolution (ADR-0021).
                'metadata' => ['qds_tenant_id' => (string) $tenant->id],
            ]));

        $id = $this->decode($response, 'create customer')['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw StripeApiException::malformed('create customer');
        }

        return $id;
    }

    /** Start a subscription Checkout session; returns the hosted page URL. */
    public function createCheckoutSession(
        string $customerId,
        string $priceId,
        string $successUrl,
        string $cancelUrl,
    ): string {
        $response = $this->send('create checkout session', fn (PendingRequest $request): Response => $request
            ->post('/checkout/sessions', [
                'mode' => 'subscription',
                'customer' => $customerId,
                'line_items' => [['price' => $priceId, 'quantity' => 1]],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ]));

        return $this->url($response, 'create checkout session');
    }

    /** Open a Billing Portal session; returns the hosted page URL. */
    public function createBillingPortalSession(string $customerId, string $returnUrl): string
    {
        $response = $this->send('create billing portal session', fn (PendingRequest $request): Response => $request
            ->post('/billing_portal/sessions', [
                'customer' => $customerId,
                'return_url' => $returnUrl,
            ]));

        return $this->url($response, 'create billing portal session');
    }

    /**
     * Fetch a subscription's current canonical state — used when an event
     * (checkout.session.completed) carries only the subscription id.
     *
     * @return array<string, mixed>
     */
    public function getSubscription(string $subscriptionId): array
    {
        $response = $this->send(
            'get subscription',
            fn (PendingRequest $request): Response => $request->get('/subscriptions/'.$subscriptionId),
        );

        return $this->decode($response, 'get subscription');
    }

    /** @param  \Closure(PendingRequest): Response  $call */
    private function send(string $operation, \Closure $call): Response
    {
        try {
            return $call($this->request($operation));
        } catch (ConnectionException) {
            throw StripeApiException::network($operation);
        }
    }

    private function request(string $operation): PendingRequest
    {
        $secret = (string) config('services.stripe.secret');

        if ($secret === '') {
            throw StripeApiException::notConfigured($operation);
        }

        return Http::withToken($secret)
            ->baseUrl((string) config('services.stripe.base_url'))
            ->timeout((int) config('services.stripe.timeout'))
            ->asForm()
            ->throw(function (Response $response) use ($operation): void {
                // Stripe error envelopes carry a code — safe to keep; the
                // body itself (may echo emails/names) is never propagated.
                $code = $response->json('error.code');

                throw StripeApiException::http(
                    $operation,
                    $response->status(),
                    is_string($code) ? $code : null,
                );
            });
    }

    /** @return array<string, mixed> */
    private function decode(Response $response, string $operation): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw StripeApiException::malformed($operation);
        }

        return $decoded;
    }

    private function url(Response $response, string $operation): string
    {
        $url = $this->decode($response, $operation)['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw StripeApiException::malformed($operation);
        }

        return $url;
    }
}
