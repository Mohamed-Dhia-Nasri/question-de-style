<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0021 — the tenant becomes the billable Stripe customer.
 *
 * ENT-Tenant gains the Stripe customer handle deliberately deferred by
 * ADR-0019 ("billing columns intentionally absent"). One tenant ↔ one Stripe
 * customer; individual staff users are never separate Stripe customers.
 * The column is NOT mass assignable (Tenant::$fillable stays ['name']) —
 * it is force-filled once by BillingManager::ensureCustomer() under a row
 * lock, and webhooks resolve tenants ONLY through this trusted mapping
 * (never through payload metadata).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('stripe_customer_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('stripe_customer_id');
        });
    }
};
