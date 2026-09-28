<?php

use App\Http\Controllers\App\CrmSettingsController;
use App\Http\Controllers\App\CustomerController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\LeadActionController;
use App\Http\Controllers\App\LeadController;
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

        Route::middleware('module:leads')->group(function () {
            Route::get('/leads', [LeadController::class, 'index'])->middleware('can:leads.view')->name('leads.index');
            Route::get('/leads/create', [LeadController::class, 'create'])->middleware('can:leads.create')->name('leads.create');
            Route::post('/leads', [LeadController::class, 'store'])->middleware('can:leads.create')->name('leads.store');
            Route::post('/leads/bulk', [LeadActionController::class, 'bulk'])->middleware('can:leads.view')->name('leads.bulk');
            Route::get('/leads/{lead}', [LeadController::class, 'show'])->middleware('can:leads.view')->name('leads.show');
            Route::get('/leads/{lead}/edit', [LeadController::class, 'edit'])->middleware('can:leads.update')->name('leads.edit');
            Route::put('/leads/{lead}', [LeadController::class, 'update'])->middleware('can:leads.update')->name('leads.update');
            Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->middleware('can:leads.delete')->name('leads.destroy');
            Route::patch('/leads/{lead}/stage', [LeadActionController::class, 'stage'])->middleware('can:leads.update')->name('leads.stage');
            Route::patch('/leads/{lead}/assign', [LeadActionController::class, 'assign'])->middleware('can:leads.assign')->name('leads.assign');
            Route::post('/leads/{lead}/activities', [LeadActionController::class, 'activity'])->middleware('can:leads.update')->name('leads.activities.store');

            Route::get('/settings/crm', [CrmSettingsController::class, 'show'])->middleware('can:settings.view')->name('settings.crm');
            Route::middleware('can:settings.update')->group(function () {
                Route::put('/settings/crm/stages', [CrmSettingsController::class, 'updateStages'])->name('settings.crm.stages');
                Route::put('/settings/crm/sources', [CrmSettingsController::class, 'updateSources'])->name('settings.crm.sources');
                Route::put('/settings/crm/assignment', [CrmSettingsController::class, 'updateAssignment'])->name('settings.crm.assignment');
            });
        });

        Route::middleware('module:customers')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index'])->middleware('can:customers.view')->name('customers.index');
            Route::get('/customers/create', [CustomerController::class, 'create'])->middleware('can:customers.create')->name('customers.create');
            Route::post('/customers', [CustomerController::class, 'store'])->middleware('can:customers.create')->name('customers.store');
            Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('can:customers.view')->name('customers.show');
            Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->middleware('can:customers.update')->name('customers.edit');
            Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware('can:customers.update')->name('customers.update');
            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('can:customers.delete')->name('customers.destroy');
            Route::post('/customers/{customer}/activities', [CustomerController::class, 'activity'])->middleware('can:customers.update')->name('customers.activities.store');
        });
    });
});
