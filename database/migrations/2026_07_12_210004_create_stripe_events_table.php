<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe webhook idempotency ledger (ADR-0021).
 *
 * GLOBAL infrastructure table (the provider-telemetry class of ADR-0019 §2
 * — tenant resolution happens per event, after dedup). One row per Stripe
 * event id ever accepted; the unique index is the dedup mechanism: the
 * webhook controller INSERTs inside the processing transaction, so a
 * duplicate delivery conflicts and is acknowledged without reprocessing,
 * while a FAILED processing run rolls the row back and lets Stripe's retry
 * try again. Append-only; deliberately stores NO payload — event ids and
 * types only, never payment data (billing security, ADR-0021).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table): void {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type', 60);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
