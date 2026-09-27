<?php

use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\SettingsController;
use App\Http\Controllers\App\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
| Business app (config('autowave.hosts.app')). Authentication routes (login,
| register, password reset, email verification) are registered by Fortify.
*/

Route::redirect('/', '/dashboard');

Route::middleware(['auth', 'active', 'verified'])->group(function () {
    Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('/workspaces/{tenant}/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');

    Route::middleware('tenant.member')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/settings', SettingsController::class)->middleware('can:settings.view')->name('settings');
    });
});
