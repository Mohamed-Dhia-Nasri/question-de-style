<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ENT-TenantSubscription (ADR-0021) — a tenant's Stripe subscription:
 * docs/30-data-model/00-data-model.md#ent-tenantsubscription.
 *
 * Tenant-owned (NOT NULL tenant_id per ADR-0019 §3). State is a mirror of
 * Stripe's canonical subscription object, written ONLY by the webhook
 * synchronizer (SubscriptionSynchronizer) — the app never invents its own
 * lifecycle transitions. Terminal rows (CANCELED / INCOMPLETE_EXPIRED) are
 * kept as history; the partial unique index below enforces at most ONE
 * live subscription per tenant. Keep its WHERE list in sync with
 * SubscriptionStatus::terminal().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained('tenants');
            // Plan resolution comes from the Stripe price id on subscription
            // events; the FK is restrictive — plans referenced by any
            // subscription (even historical) cannot be deleted.
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans');
            $table->string('stripe_subscription_id')->unique();
            $table->string('status', 30)->index();
            // Bespoke per-tenant seat allowance; NULL = the plan's max_seats.
            $table->unsignedInteger('seats_override')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            // Out-of-order webhook guard: the Stripe event.created of the
            // last applied subscription event — older events are skipped.
            $table->timestamp('last_stripe_event_at')->nullable();
            $table->timestamps();
        });

        // One live subscription per tenant (the emv_configurations_one_active
        // precedent). Terminal set = SubscriptionStatus::terminal().
        DB::statement(
            'CREATE UNIQUE INDEX tenant_subscriptions_one_live_index '
            ."ON tenant_subscriptions (tenant_id) WHERE status NOT IN ('CANCELED', 'INCOMPLETE_EXPIRED')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscriptions');
    }
};
