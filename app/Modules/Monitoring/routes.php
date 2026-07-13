<?php

use App\Modules\Monitoring\Http\Controllers\StoryMediaController;
use App\Platform\Export\Http\ExportDownloadController;
use App\Shared\Authorization\PermissionsCatalog;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:'.PermissionsCatalog::MONITORING_VIEW, 'subscribed'])
    ->prefix('monitoring')
    ->as('monitoring.')
    ->group(function () {
        // Monitoring Overview dashboard (REQ-M1-012).
        Route::view('/', 'monitoring.index')->name('index');

        // Roster creators list + per-creator detail (REQ-M1-001/005/007).
        Route::view('/creators', 'monitoring.creators')->name('creators.index');
        Route::view('/creators/{creator}', 'monitoring.creator-detail')->name('creators.show');

        // Content detail with review actions (REQ-M1-003/005/006, DP-004).
        Route::view('/content/{contentItem}', 'monitoring.content-detail')->name('content.show');

        // DP-004 human review queue (decisions re-authorize on
        // monitoring.manage inside ReviewService).
        Route::view('/review', 'monitoring.review')->name('review');

        // EMV configurations (REQ-M1-011; mutations re-authorize on
        // emv.manage inside EmvConfigurationService).
        Route::view('/emv', 'monitoring.emv')->name('emv');

        // Configured hashtag lists — matching evidence registry (mutations
        // re-authorize on monitoring.manage via HashtagListPolicy).
        Route::view('/hashtags', 'monitoring.hashtags')->name('hashtags.index');

        // Report exports (REQ-M1-012; exports.create via ExportJobPolicy).
        Route::view('/exports', 'monitoring.exports')->name('exports.index');

        // Internal operational observability (operations.view; never
        // CLIENT_VIEWER — provider health details are staff-only).
        Route::view('/operations', 'monitoring.operations')
            ->middleware('can:'.PermissionsCatalog::OPERATIONS_VIEW)
            ->name('operations');

        // Operator-chosen monitoring plan: polling frequencies + cost
        // estimate (product-owner decision 2026-07-08). Mutating spend
        // posture is a manage action, not a view.
        Route::view('/plan', 'monitoring.plan')
            ->middleware('can:'.PermissionsCatalog::MONITORING_MANAGE)
            ->name('plan');

        // Mint a short-lived signed URL for archived story media
        // (REQ-M1-004; private object storage, StoryPolicy-checked).
        Route::get('/stories/{story}/media-url', [StoryMediaController::class, 'issue'])
            ->name('stories.media-url');
    });

// Export artifact download: authenticated + signed + policy-checked, and
// the artifact must not be expired. Never a public URL (REQ-M1-012).
Route::middleware(['web', 'auth', 'signed', 'subscribed'])
    ->get('/exports/{exportJob}/download', ExportDownloadController::class)
    ->name('exports.download');

// Serve archived story media. Authenticated (so SetTenantContext binds the
// viewer's tenant and the {story} binding resolves tenant-scoped — a
// foreign-tenant id 404s) + a valid unexpired signature + a StoryPolicy
// re-check inside stream(). The signature is one factor, no longer the sole
// bearer credential for another tenant's private media (ADR-0019 hard
// enforcement). Lifetime is qds.ingestion.signed_url_ttl_minutes.
Route::middleware(['web', 'auth', 'signed', 'subscribed'])
    ->get('/monitoring/stories/{story}/media', [StoryMediaController::class, 'stream'])
    ->name('monitoring.stories.media');
