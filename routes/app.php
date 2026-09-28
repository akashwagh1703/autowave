<?php

use App\Http\Controllers\App\AppointmentActionController;
use App\Http\Controllers\App\AppointmentController;
use App\Http\Controllers\App\BookingResourceController;
use App\Http\Controllers\App\BookingSettingsController;
use App\Http\Controllers\App\CrmSettingsController;
use App\Http\Controllers\App\CustomerController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\LeadActionController;
use App\Http\Controllers\App\LeadController;
use App\Http\Controllers\App\OnboardingController;
use App\Http\Controllers\App\ServiceCategoryController;
use App\Http\Controllers\App\ServiceController;
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

        Route::middleware('engine:service')->group(function () {
            Route::get('/services', [ServiceController::class, 'index'])->middleware('can:services.view')->name('services.index');

            Route::middleware('can:services.manage')->group(function () {
                Route::get('/services/create', [ServiceController::class, 'create'])->name('services.create');
                Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
                Route::post('/services/bulk', [ServiceController::class, 'bulk'])->name('services.bulk');
                Route::get('/services/{service}/edit', [ServiceController::class, 'edit'])->name('services.edit');
                Route::put('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
                Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');

                Route::post('/service-categories', [ServiceCategoryController::class, 'store'])->name('service-categories.store');
                Route::put('/service-categories/{category}', [ServiceCategoryController::class, 'update'])->name('service-categories.update');
                Route::delete('/service-categories/{category}', [ServiceCategoryController::class, 'destroy'])->name('service-categories.destroy');
            });
        });

        Route::middleware('engine:booking')->group(function () {
            Route::get('/resources', [BookingResourceController::class, 'index'])->middleware('can:resources.view')->name('resources.index');
            Route::get('/resources/create', [BookingResourceController::class, 'create'])->middleware('can:resources.manage')->name('resources.create');
            Route::post('/resources', [BookingResourceController::class, 'store'])->middleware('can:resources.manage')->name('resources.store');
            Route::get('/resources/{bookingResource}', [BookingResourceController::class, 'show'])->middleware('can:resources.view')->name('resources.show');

            Route::middleware('can:resources.manage')->group(function () {
                Route::get('/resources/{bookingResource}/edit', [BookingResourceController::class, 'edit'])->name('resources.edit');
                Route::put('/resources/{bookingResource}', [BookingResourceController::class, 'update'])->name('resources.update');
                Route::delete('/resources/{bookingResource}', [BookingResourceController::class, 'destroy'])->name('resources.destroy');
                Route::post('/resources/{bookingResource}/time-off', [BookingResourceController::class, 'storeTimeOff'])->name('resources.time-off.store');
                Route::delete('/resources/{bookingResource}/time-off/{timeOff}', [BookingResourceController::class, 'destroyTimeOff'])->name('resources.time-off.destroy');
            });

            Route::get('/appointments', [AppointmentController::class, 'calendar'])->middleware('can:appointments.view')->name('appointments.calendar');
            Route::get('/appointments/list', [AppointmentController::class, 'index'])->middleware('can:appointments.view')->name('appointments.index');
            Route::get('/appointments/availability', [AppointmentController::class, 'availability'])->middleware(['can:appointments.view', 'throttle:120,1'])->name('appointments.availability');
            Route::get('/appointments/customers', [AppointmentController::class, 'customers'])->middleware(['can:appointments.create', 'throttle:120,1'])->name('appointments.customers');
            Route::get('/appointments/create', [AppointmentController::class, 'create'])->middleware('can:appointments.create')->name('appointments.create');
            Route::post('/appointments', [AppointmentController::class, 'store'])->middleware('can:appointments.create')->name('appointments.store');
            Route::post('/appointments/bulk', [AppointmentActionController::class, 'bulk'])->middleware('can:appointments.view')->name('appointments.bulk');
            Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])->middleware('can:appointments.view')->name('appointments.show');
            Route::put('/appointments/{appointment}', [AppointmentController::class, 'update'])->middleware('can:appointments.update')->name('appointments.update');
            Route::patch('/appointments/{appointment}/status', [AppointmentActionController::class, 'status'])->middleware('can:appointments.view')->name('appointments.status');
            Route::patch('/appointments/{appointment}/reschedule', [AppointmentActionController::class, 'reschedule'])->middleware('can:appointments.update')->name('appointments.reschedule');

            Route::get('/settings/booking', [BookingSettingsController::class, 'show'])->middleware('can:settings.view')->name('settings.booking');
            Route::put('/settings/booking', [BookingSettingsController::class, 'update'])->middleware('can:settings.update')->name('settings.booking.update');
        });
    });
});
