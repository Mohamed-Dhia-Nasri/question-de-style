<?php

use App\Shared\Authorization\PermissionsCatalog;
use Illuminate\Support\Facades\Route;

// Operational endpoints (/up and /health) are registered in bootstrap/app.php
// outside the web middleware group so they never depend on the session store.

Route::get('/', fn () => redirect()->route('dashboard'));

// Internal staff surfaces. CLIENT_VIEWER lacks internal.access and is
// confined to the approved-reports area (REQ-M3-012). 'subscribed' is the
// ADR-0021 product gate — the account/billing area stays outside it.
Route::middleware(['auth', 'can:'.PermissionsCatalog::INTERNAL_ACCESS, 'subscribed'])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');
});

// Approved client reports — the ONLY surface CLIENT_VIEWER may access.
// Brand-level scoping ("their own brands") is enforced additionally by a
// report policy once ENT-Client/ENT-Brand and report entities exist (P3);
// this route is the authorization boundary it will plug into. Product
// surface → subscription-gated like every module area (ADR-0021).
Route::middleware(['auth', 'can:'.PermissionsCatalog::REPORTS_VIEW_APPROVED, 'subscribed'])->group(function () {
    Route::view('/reports', 'reports.index')->name('reports.index');
});

// Module areas (monitoring, discovery, crm, admin/users) are registered by
// their module service providers — see app/Modules/*/routes.php.
