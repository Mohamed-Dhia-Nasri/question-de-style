<?php

use App\Shared\Authorization\PermissionsCatalog;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:'.PermissionsCatalog::CRM_VIEW])
    ->prefix('crm')
    ->as('crm.')
    ->group(function () {
        Route::view('/', 'crm.index')->name('index');
    });

// User administration — ENT-User/ENT-Role writes are ADMIN-only
// (ownership matrix, REQ-M3-012). Route-level gate + Livewire-side
// authorization + UserPolicy all enforce the same permission.
Route::middleware(['web', 'auth', 'can:'.PermissionsCatalog::USERS_MANAGE])
    ->prefix('admin')
    ->as('admin.')
    ->group(function () {
        Route::view('/users', 'crm.users')->name('users.index');
    });
