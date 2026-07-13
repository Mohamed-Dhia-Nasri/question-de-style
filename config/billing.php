<?php

// SaaS billing configuration (ADR-0021). Stripe CREDENTIALS live in
// config/services.php (services.stripe.*) — this file only holds behavior
// toggles and the plan catalog. Canonical entity/enum definitions live in
// docs/ as always.

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription enforcement
    |--------------------------------------------------------------------------
    | Master switch for server-side subscription gating (EnsureTenantSubscribed
    | middleware + seat limits). OFF by default: the founding tenant predates
    | billing and commercial plan values are a pending product-owner decision
    | (the QDS_INGESTION_ENABLED precedent — the code path is complete and
    | tested, the rollout is an explicit operator action). When OFF, product
    | access is not gated and seat allowances are unlimited; invitations,
    | webhooks, checkout, and the billing portal all still work.
    */
    'enforced' => (bool) env('QDS_BILLING_ENFORCED', false),

    /*
    |--------------------------------------------------------------------------
    | Team invitations
    |--------------------------------------------------------------------------
    */
    'invitation_expiry_days' => (int) env('QDS_BILLING_INVITATION_EXPIRY_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Plan catalog
    |--------------------------------------------------------------------------
    | Synced idempotently into subscription_plans by qds:billing-sync-plans
    | (and the database seeder) — the DB rows are what the app reads; this
    | array is their single source. Codes are stable identifiers: renaming a
    | code creates a NEW plan. Stripe price ids and seat counts come from the
    | environment — final commercial values are a pending product-owner
    | decision (ADR-0021), so nothing commercial is hard-coded here.
    */
    'plans' => [
        [
            'code' => 'STARTER',
            'name' => 'Starter',
            'stripe_price_id' => env('STRIPE_PRICE_STARTER'),
            'billing_interval' => 'MONTH',
            'max_seats' => (int) env('QDS_BILLING_STARTER_SEATS', 5),
            'features' => [],
            'is_active' => true,
        ],
        [
            'code' => 'GROWTH',
            'name' => 'Growth',
            'stripe_price_id' => env('STRIPE_PRICE_GROWTH'),
            'billing_interval' => 'MONTH',
            'max_seats' => (int) env('QDS_BILLING_GROWTH_SEATS', 15),
            'features' => [],
            'is_active' => true,
        ],
        [
            'code' => 'AGENCY',
            'name' => 'Agency',
            'stripe_price_id' => env('STRIPE_PRICE_AGENCY'),
            'billing_interval' => 'MONTH',
            'max_seats' => (int) env('QDS_BILLING_AGENCY_SEATS', 40),
            'features' => [],
            'is_active' => true,
        ],
    ],

];
