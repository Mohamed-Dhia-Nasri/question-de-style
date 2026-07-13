<?php

use App\Shared\Authorization\PermissionsCatalog;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:'.PermissionsCatalog::DISCOVERY_VIEW, 'subscribed'])
    ->prefix('discovery')
    ->as('discovery.')
    ->group(function () {
        Route::view('/', 'discovery.index')->name('index');
    });
