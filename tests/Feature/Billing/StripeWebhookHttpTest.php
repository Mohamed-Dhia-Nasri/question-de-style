<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithStripe;
use Tests\TestCase;

/**
 * Transport-level contract of POST /webhooks/stripe (ADR-0021).
 *
 * The webhook is the ONLY unauthenticated write surface of the billing
 * module: its entire security model is the Stripe-Signature HMAC over the
 * raw body plus a bounded replay window, and its reliability model is the
 * one-transaction dedup ledger (stripe_events) whose row must survive
 * success, disappear on failure (so Stripe's retry is not swallowed), and
 * short-circuit duplicates. Every test here asserts an observable outcome:
 * response status/body, stripe_events rows, tenant_subscriptions rows.
 *
 * Signatures are minted locally via InteractsWithStripe — the verifier is
 * SDK-free HMAC math, so valid AND invalid headers can be forged offline.
 */
class StripeWebhookHttpTest extends TestCase
{
    use InteractsWithStripe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripeCredentials();

        // Pin the replay window so the stale-timestamp test is explicit
        // about the boundary it crosses, not dependent on env defaults.
        config(['services.stripe.webhook_tolerance_seconds' => 300]);
    }

    public function test_valid_signature_with_ignored_event_type_returns_ok_and_records_the_event(): void
    {
        // 'customer.created' hits the synchronizer's default (ignore)
        // branch — the transport must still acknowledge AND ledger it so a
        // redelivery of the same id is deduplicated.
        $event = $this->stripeEvent('customer.created', ['id' => 'cus_benign', 'object' => 'customer'], 'evt_benign');

        $response = $this->postStripeWebhook($event);

        $response->assertStatus(200);
        $this->assertSame('ok', $response->getContent());
        $this->assertDatabaseHas('stripe_events', [
            'stripe_event_id' => 'evt_benign',
            'type' => 'customer.created',
        ]);
    }

    public function test_signature_minted_with_wrong_secret_is_rejected_without_recording(): void
    {
        $event = $this->stripeEvent('customer.created', ['id' => 'cus_x'], 'evt_wrong_secret');
        $payload = json_encode($event, JSON_THROW_ON_ERROR);

        // A structurally perfect header whose HMAC was computed with a
        // different endpoint secret — an attacker without OUR secret.
        $forged = $this->stripeSignature($payload, null, 'whsec_not_ours');

        $response = $this->postStripeWebhook($event, $forged);

        $response->assertStatus(400);
        $this->assertSame('invalid signature', $response->getContent());
        $this->assertDatabaseCount('stripe_events', 0);
    }

    public function test_missing_stripe_signature_header_is_rejected(): void
    {
        $payload = json_encode(
            $this->stripeEvent('customer.created', ['id' => 'cus_x'], 'evt_no_header'),
            JSON_THROW_ON_ERROR,
        );

        // Bypass the trait helper: it always signs. Post the raw body with
        // NO Stripe-Signature header at all.
        $response = $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(400);
        $this->assertSame('invalid signature', $response->getContent());
        $this->assertDatabaseCount('stripe_events', 0);
    }

    public function test_signature_older_than_the_replay_tolerance_is_rejected(): void
    {
        $event = $this->stripeEvent('customer.created', ['id' => 'cus_x'], 'evt_stale');
        $payload = json_encode($event, JSON_THROW_ON_ERROR);

        // Correct secret, correct HMAC — but signed 4000s ago, far outside
        // the 300s window. A captured delivery must not be replayable later.
        $stale = $this->stripeSignature($payload, now()->getTimestamp() - 4000);

        $response = $this->postStripeWebhook($event, $stale);

        $response->assertStatus(400);
        $this->assertSame('invalid signature', $response->getContent());
        $this->assertDatabaseCount('stripe_events', 0);
    }

    public function test_fresh_timestamp_with_altered_payload_is_rejected(): void
    {
        $event = $this->stripeEvent('customer.created', ['id' => 'cus_x'], 'evt_tampered');

        // Sign the ORIGINAL body with a timestamp comfortably inside the
        // tolerance, then mutate the event before posting: the timestamp
        // check passes, the HMAC over the altered body must not.
        $signature = $this->stripeSignature(
            json_encode($event, JSON_THROW_ON_ERROR),
            now()->getTimestamp() - 60,
        );

        $event['type'] = 'customer.subscription.deleted'; // attacker edit after signing

        $response = $this->postStripeWebhook($event, $signature);

        $response->assertStatus(400);
        $this->assertSame('invalid signature', $response->getContent());
        $this->assertDatabaseCount('stripe_events', 0);
    }

    public function test_missing_webhook_secret_config_returns_server_error(): void
    {
        // Misconfiguration is OUR fault, not the caller's: 500 tells Stripe
        // to keep retrying until the endpoint is configured, instead of a
        // 400 that would eventually disable the endpoint.
        config(['services.stripe.webhook_secret' => '']);

        $event = $this->stripeEvent('customer.created', ['id' => 'cus_x'], 'evt_unconfigured');

        $response = $this->postStripeWebhook($event, 't=123,v1=irrelevant');

        $response->assertStatus(500);
        $this->assertDatabaseCount('stripe_events', 0);
    }

    public function test_malformed_json_body_with_valid_signature_is_rejected(): void
    {
        // Truncated JSON, but the signature is computed over THAT exact
        // body — so the request clears signature verification and must be
        // caught by the payload shape check (the 'malformed payload' body
        // proves which 400 branch fired).
        $payload = '{"id":"evt_broken","type":';

        $response = $this->call('POST', '/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(400);
        $this->assertSame('malformed payload', $response->getContent());
        $this->assertDatabaseCount('stripe_events', 0);
    }

    public function test_duplicate_delivery_is_acknowledged_once_and_side_effects_apply_once(): void
    {
        $plan = SubscriptionPlan::factory()->create();

        $tenant = $this->defaultTenant;
        $tenant->forceFill(['stripe_customer_id' => 'cus_dup'])->save();

        // customer.subscription.created syncs straight from the payload —
        // no outbound Stripe call — so the side effect is one local row.
        $event = $this->stripeEvent(
            'customer.subscription.created',
            $this->stripeSubscriptionObject('sub_dup', 'cus_dup', $plan->stripe_price_id),
            'evt_dup',
        );

        $first = $this->postStripeWebhook($event);
        $second = $this->postStripeWebhook($event); // Stripe redelivers the SAME event id

        $first->assertStatus(200);
        $this->assertSame('ok', $first->getContent());
        $second->assertStatus(200);
        $this->assertSame('duplicate', $second->getContent());

        // Dedup ledger holds exactly one row for the id, and the sync ran
        // exactly once: one subscription row, correctly attributed.
        $this->assertSame(1, DB::table('stripe_events')->where('stripe_event_id', 'evt_dup')->count());
        $this->assertDatabaseCount('tenant_subscriptions', 1);
        $this->assertDatabaseHas('tenant_subscriptions', [
            'stripe_subscription_id' => 'sub_dup',
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_processing_failure_rolls_back_the_dedup_row_so_a_retry_succeeds(): void
    {
        $plan = SubscriptionPlan::factory()->create();

        $tenant = $this->defaultTenant;
        $tenant->forceFill(['stripe_customer_id' => 'cus_roll'])->save();

        // checkout.session.completed forces an outbound subscription fetch.
        // First call: Stripe API is down (500) → StripeApiException inside
        // the ledger transaction. Second call: recovered.
        Http::fake([
            'api.stripe.com/v1/subscriptions/*' => Http::sequence()
                ->push(['error' => ['code' => 'api_error']], 500)
                ->push($this->stripeSubscriptionObject('sub_roll', 'cus_roll', $plan->stripe_price_id)),
        ]);

        $event = $this->stripeEvent('checkout.session.completed', [
            'id' => 'cs_roll',
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'customer' => 'cus_roll',
            'subscription' => 'sub_roll',
        ], 'evt_roll');

        $failed = $this->postStripeWebhook($event);

        $failed->assertStatus(500);
        // The dedup row must roll back with the failure — otherwise this
        // very retry would be answered 'duplicate' and the event lost.
        $this->assertDatabaseMissing('stripe_events', ['stripe_event_id' => 'evt_roll']);
        $this->assertDatabaseCount('tenant_subscriptions', 0);

        $retry = $this->postStripeWebhook($event);

        $retry->assertStatus(200);
        $this->assertSame('ok', $retry->getContent());
        $this->assertDatabaseHas('stripe_events', ['stripe_event_id' => 'evt_roll']);
        $this->assertDatabaseHas('tenant_subscriptions', [
            'stripe_subscription_id' => 'sub_roll',
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_rotated_secret_header_with_multiple_v1_candidates_is_accepted(): void
    {
        // During secret rotation Stripe signs with BOTH secrets and ships
        // two v1 entries; only one needs to match. Put the wrong one first
        // to prove every candidate is tried.
        $event = $this->stripeEvent('customer.created', ['id' => 'cus_x'], 'evt_rotation');
        $payload = json_encode($event, JSON_THROW_ON_ERROR);

        $timestamp = now()->getTimestamp();
        $wrong = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_retired_secret');
        $correct = hash_hmac('sha256', $timestamp.'.'.$payload, (string) config('services.stripe.webhook_secret'));

        $response = $this->postStripeWebhook($event, 't='.$timestamp.',v1='.$wrong.',v1='.$correct);

        $response->assertStatus(200);
        $this->assertSame('ok', $response->getContent());
        $this->assertDatabaseHas('stripe_events', ['stripe_event_id' => 'evt_rotation']);
    }

    public function test_endpoint_processes_guest_requests_without_session_or_csrf(): void
    {
        // Raw guest POST: no authenticated user, no CSRF token, no prior
        // session. Registered outside the web group, the route must neither
        // 419 nor start a session.
        $event = $this->stripeEvent('customer.created', ['id' => 'cus_guest'], 'evt_guest');

        $response = $this->postStripeWebhook($event);

        $response->assertStatus(200); // notably NOT 419 TokenMismatch
        $this->assertSame('ok', $response->getContent());
        $this->assertDatabaseHas('stripe_events', ['stripe_event_id' => 'evt_guest']);

        // A stateless endpoint must not hand out a session cookie.
        $cookieNames = array_map(
            static fn ($cookie) => $cookie->getName(),
            $response->headers->getCookies(),
        );
        $this->assertNotContains(config('session.cookie'), $cookieNames);
    }

    public function test_wrong_signature_of_correct_length_is_rejected_without_error(): void
    {
        // hash_equals demands same-length inputs for its constant-time
        // guarantee: a 64-hex-char candidate that is wrong in VALUE must
        // fall through to a clean 400 — no exception, no acceptance.
        $event = $this->stripeEvent('customer.created', ['id' => 'cus_x'], 'evt_constant_time');

        $response = $this->postStripeWebhook(
            $event,
            't='.now()->getTimestamp().',v1='.str_repeat('0f', 32), // 64 chars, valid hex, wrong value
        );

        $response->assertStatus(400);
        $this->assertSame('invalid signature', $response->getContent());
        $this->assertDatabaseCount('stripe_events', 0);
    }
}
