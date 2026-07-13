<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Http\StripeWebhookSignature;
use App\Modules\Billing\Services\SubscriptionSynchronizer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /webhooks/stripe (ADR-0021).
 *
 * Registered OUTSIDE the web group on purpose: no session, no CSRF — the
 * request is authenticated exclusively by its Stripe-Signature (HMAC over
 * the raw body with the endpoint secret, bounded replay window).
 *
 * Idempotency + retry safety share one transaction: the event id is
 * INSERTed into stripe_events and the event processed atomically. A
 * duplicate delivery conflicts on the unique index → acknowledged without
 * reprocessing (a concurrent duplicate blocks on the in-flight insert and
 * resolves the same way after commit). A processing FAILURE rolls the
 * ledger row back and answers 500, so Stripe's retry is not swallowed by
 * its own dedup entry.
 */
class StripeWebhookController
{
    public function __invoke(
        Request $request,
        StripeWebhookSignature $signature,
        SubscriptionSynchronizer $synchronizer,
    ): Response {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '') {
            Log::error('stripe webhook: no STRIPE_WEBHOOK_SECRET configured');

            return response('webhook not configured', 500);
        }

        $payload = $request->getContent();

        $valid = $signature->verify(
            $payload,
            $request->header('Stripe-Signature'),
            $secret,
            (int) config('services.stripe.webhook_tolerance_seconds'),
        );

        if (! $valid) {
            Log::warning('stripe webhook: invalid signature rejected');

            return response('invalid signature', 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event)
            || ! is_string($event['id'] ?? null)
            || ! is_string($event['type'] ?? null)) {
            return response('malformed payload', 400);
        }

        try {
            $processed = DB::transaction(function () use ($event, $synchronizer): bool {
                $inserted = DB::table('stripe_events')->insertOrIgnore([
                    'stripe_event_id' => $event['id'],
                    'type' => $event['type'],
                    'created_at' => now(),
                ]);

                if ($inserted === 0) {
                    return false; // duplicate delivery — already processed
                }

                $synchronizer->handle($event);

                return true;
            });
        } catch (Throwable $e) {
            report($e);

            return response('processing failed', 500);
        }

        return response($processed ? 'ok' : 'duplicate', 200);
    }
}
