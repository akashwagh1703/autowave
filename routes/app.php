<?php

use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\OnboardingController;
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

    Route::get('/onboarding', [OnboardingController::class, 'create'])->name('onboarding.create');
    Route::get('/onboarding/slug', [OnboardingController::class, 'slug'])->middleware('throttle:60,1')->name('onboarding.slug');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->middleware('throttle:onboarding')->name('onboarding.store');

    Route::middleware('tenant.member')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/settings', SettingsController::class)->middleware('can:settings.view')->name('settings');
    });
});
