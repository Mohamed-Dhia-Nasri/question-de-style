<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ENT-SubscriptionPlan (ADR-0021) — the commercial plan catalog:
 * docs/30-data-model/00-data-model.md#ent-subscriptionplan.
 *
 * GLOBAL table (like the spatie role/permission definitions): plans are
 * platform-level configuration, not tenant data — tenants reference a plan
 * through their subscription. Rows are synced idempotently from
 * config/billing.php by qds:billing-sync-plans; there is no UI write path.
 * Commercial values (price ids, seat counts) come from the environment —
 * nothing commercial is hard-coded (product-owner decision pending, the
 * cadence-config precedent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            // Stable machine code (STARTER, …) — the config sync key. Avoids
            // the bare "plan" vocabulary already taken by monitoring plan
            // settings (ingestion cadence) and PollPlan (cycle scheduling).
            $table->string('code', 40)->unique();
            $table->string('name');
            // Stripe price this plan sells as. Nullable so a plan can be
            // catalogued before its Stripe price exists (checkout is blocked
            // until set); unique because two plans must never share a price
            // or webhook plan-resolution becomes ambiguous.
            $table->string('stripe_price_id')->nullable()->unique();
            $table->string('billing_interval', 10);
            // Seat allowance the plan grants (ADR-0021 seat model).
            $table->unsignedInteger('max_seats');
            // Feature entitlement flags — reserved for plan gating beyond
            // seats; empty for the launch catalog.
            $table->jsonb('features')->default('[]');
            // Inactive plans stay resolvable for existing subscriptions but
            // are not offered for new checkout.
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
