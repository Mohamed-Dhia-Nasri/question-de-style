<?php

use App\Modules\CRM\Http\Controllers\DocumentDownloadController;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Authorization\PermissionsCatalog;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:'.PermissionsCatalog::CRM_VIEW, 'subscribed'])
    ->prefix('crm')
    ->as('crm.')
    ->group(function () {
        Route::view('/', 'crm.index')->name('index');

        // Step 2 — operator-managed identity (ADR-0014): CRM's own creators
        // list and the creator profile. Route gate is crm.view; every
        // mutating Livewire action re-authorizes crm.manage server-side.
        Route::view('/creators', 'crm.creators')->name('creators.index');
        Route::get('/creators/{creator}', fn (Creator $creator) => view('crm.creator-profile', ['creator' => $creator]))
            ->name('creators.show');

        // Step 3 — master data, campaigns, seeding, shipments
        // (REQ-M3-005/006/007) + the operator half of content matching.
        Route::view('/clients', 'crm.clients')->name('clients.index');
        Route::view('/brands', 'crm.brands')->name('brands.index');
        Route::get('/brands/{brand}', fn (Brand $brand) => view('crm.brand-detail', [
            'brand' => $brand->load('client')->loadCount(['products', 'campaigns', 'seedingCampaigns']),
        ]))->name('brands.show');
        Route::view('/products', 'crm.products')->name('products.index');
        Route::view('/campaigns', 'crm.campaigns')->name('campaigns.index');
        Route::get('/campaigns/{campaign}', fn (Campaign $campaign) => view('crm.campaign-detail', ['campaign' => $campaign]))
            ->name('campaigns.show');
        Route::view('/seeding', 'crm.seeding')->name('seeding.index');
        Route::get('/seeding/{seedingCampaign}', fn (SeedingCampaign $seedingCampaign) => view('crm.seeding-detail', ['seedingCampaign' => $seedingCampaign]))
            ->name('seeding.show');

        // Step 4 — the cross-influencer product results dashboard
        // (REQ-M3-013, AC-M3-019). Results are rollup reads (ADR-0010):
        // the crm.view route gate suffices, no mutators exist.
        Route::view('/results', 'crm.results')->name('results');

        // Step 4 — tasks & deadlines (REQ-M3-011, AC-M3-017). Route gate
        // is crm.view; every mutating Livewire action re-authorizes
        // crm.manage server-side.
        Route::view('/tasks', 'crm.tasks')->name('tasks.index');
    });

// Document attachment download (REQ-M3-010): authenticated + signed +
// policy-checked, streamed from the private qds.documents disk. Never a
// public URL (the exports.download precedent).
Route::middleware(['web', 'auth', 'signed', 'subscribed'])
    ->get('/crm/documents/{documentAttachment}/download', DocumentDownloadController::class)
    ->name('crm.documents.download');

// User administration — ENT-User/ENT-Role writes are ADMIN-only
// (ownership matrix, REQ-M3-012). Route-level gate + Livewire-side
// authorization + UserPolicy all enforce the same permission.
Route::middleware(['web', 'auth', 'can:'.PermissionsCatalog::USERS_MANAGE])
    ->prefix('admin')
    ->as('admin.')
    ->group(function () {
        Route::view('/users', 'crm.users')->name('users.index');
    });
