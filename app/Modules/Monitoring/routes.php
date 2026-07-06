<?php

use App\Modules\Monitoring\Http\Controllers\StoryMediaController;
use App\Platform\Export\Http\ExportDownloadController;
use App\Shared\Authorization\PermissionsCatalog;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:'.PermissionsCatalog::MONITORING_VIEW])
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

        // Report exports (REQ-M1-012; exports.create via ExportJobPolicy).
        Route::view('/exports', 'monitoring.exports')->name('exports.index');

        // Internal operational observability (operations.view; never
        // CLIENT_VIEWER — provider health details are staff-only).
        Route::view('/operations', 'monitoring.operations')
            ->middleware('can:'.PermissionsCatalog::OPERATIONS_VIEW)
            ->name('operations');

        // Mint a short-lived signed URL for archived story media
        // (REQ-M1-004; private object storage, StoryPolicy-checked).
        Route::get('/stories/{story}/media-url', [StoryMediaController::class, 'issue'])
            ->name('stories.media-url');
    });

// Export artifact download: authenticated + signed + policy-checked, and
// the artifact must not be expired. Never a public URL (REQ-M1-012).
Route::middleware(['web', 'auth', 'signed'])
    ->get('/exports/{exportJob}/download', ExportDownloadController::class)
    ->name('exports.download');

// Serve archived story media for a valid, unexpired signature. The signed
// URL — minted above for an authorized user — is the access credential;
// its lifetime is qds.ingestion.signed_url_ttl_minutes.
Route::middleware(['web'])
    ->get('/monitoring/stories/{story}/media', [StoryMediaController::class, 'stream'])
    ->name('monitoring.stories.media');
