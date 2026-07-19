<?php

use App\Modules\CRM\Http\Controllers\DocumentDownloadController;
use App\Modules\CRM\Http\Controllers\ProductPhotoController;
use App\Modules\CRM\Models\Brand;
use App\Modules\CRM\Models\Campaign;
use App\Modules\CRM\Models\Creator;
use App\Modules\CRM\Models\SeedingCampaign;
use App\Shared\Authorization\PermissionsCatalog;
use App\Shared\Enums\ShipmentStatus;
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
        // Optional guided path (F01/F02). Registered BEFORE the wildcard so
        // the literal `/new` segment wins over `{campaign}` binding.
        Route::view('/campaigns/new', 'crm.campaign-wizard')->name('campaigns.create');
        Route::get('/campaigns/{campaign}', fn (Campaign $campaign) => view('crm.campaign-detail', [
            'campaign' => $campaign->load('brand.client')->loadCount(['creators', 'seedingCampaigns']),
        ]))->name('campaigns.show');
        Route::view('/seeding', 'crm.seeding')->name('seeding.index');
        // Item 6a: the progress strip's sub-counts are computed live here
        // from the Shipment table (never rollups, which lag) and are
        // read-only display — the closure never writes posted/posted_at.
        Route::get('/seeding/{seedingCampaign}', fn (SeedingCampaign $seedingCampaign) => view('crm.seeding-detail', [
            'seedingCampaign' => $seedingCampaign->load(['brand.client', 'campaign', 'product'])->loadCount([
                'creators', 'shipments',
                // Count strictly by status (M07). A Returned/Failed parcel
                // keeps its shipped_at/delivered_at timestamps by design, so an
                // orWhereNotNull branch would tally it as shipped/delivered and
                // let delivered exceed shipped.
                'shipments as shipped_count' => fn ($q) => $q->whereIn('status', [ShipmentStatus::Shipped, ShipmentStatus::InTransit, ShipmentStatus::Delivered]),
                'shipments as delivered_count' => fn ($q) => $q->where('status', ShipmentStatus::Delivered),
                'shipments as posted_count' => fn ($q) => $q->where('posted', true),
                'shipments as expected_posts_count' => fn ($q) => $q->where('posting_required', true),
            ]),
        ]))->name('seeding.show');

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

// Product reference-photo thumbnails (sub-project C, spec §6):
// authenticated + signed + policy-checked, streamed inline from the
// private media disk (the crm.documents.download precedent). Link TTL is
// qds.enrichment.visual_match.photo_link_ttl_minutes.
Route::middleware(['web', 'auth', 'signed', 'subscribed'])
    ->get('/crm/products/photos/{productReferencePhoto}', ProductPhotoController::class)
    ->name('crm.products.photo');

// User administration — ENT-User/ENT-Role writes are ADMIN-only
// (ownership matrix, REQ-M3-012). Route-level gate + Livewire-side
// authorization + UserPolicy all enforce the same permission.
Route::middleware(['web', 'auth', 'can:'.PermissionsCatalog::USERS_MANAGE])
    ->prefix('admin')
    ->as('admin.')
    ->group(function () {
        Route::view('/users', 'crm.users')->name('users.index');
    });
