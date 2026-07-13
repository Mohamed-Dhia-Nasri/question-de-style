<?php

use App\Modules\Billing\Http\Controllers\InvitationAcceptController;
use App\Modules\Billing\Http\Controllers\StripeWebhookController;
use App\Shared\Authorization\PermissionsCatalog;
use Illuminate\Support\Facades\Route;

// Account area (ADR-0021). Deliberately NOT behind the 'subscribed' gate:
// a lapsed tenant must always reach its account state and the owner must
// always reach billing recovery.
Route::middleware(['web', 'auth', 'can:'.PermissionsCatalog::INTERNAL_ACCESS])
    ->prefix('account')
    ->as('account.')
    ->group(function () {
        Route::view('/', 'account.index')->name('index');

        // Owner-only (billing.manage is the owner-attribute gate, not a
        // permission): subscription management, checkout, billing portal.
        Route::view('/billing', 'account.billing')
            ->middleware('can:billing.manage')
            ->name('billing');
    });

// Guest invitation acceptance (the reset-password/{token} shape): the token
// is the credential — a 64-char random string whose hash is the only lookup
// key. Skip-listed alongside reset-password in the auth-required route
// architecture test; every security decision is re-made atomically at POST
// time under the tenant seat lock (TeamInvitationAccepter).
Route::middleware(['web'])->group(function () {
    Route::get('/invitations/{token}', [InvitationAcceptController::class, 'show'])
        ->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationAcceptController::class, 'store'])
        ->name('invitations.accept');
});

// Stripe webhook — registered OUTSIDE the web group on purpose: no session,
// no CSRF; the request is authenticated exclusively by its Stripe-Signature
// (the /health precedent for group-less routes). Global middleware
// (request id, secure headers) still applies.
Route::post('/webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');
