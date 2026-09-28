<?php

use App\Http\Controllers\App\AppointmentActionController;
use App\Http\Controllers\App\AppointmentController;
use App\Http\Controllers\App\AutomationController;
use App\Http\Controllers\App\AutomationRunController;
use App\Http\Controllers\App\BookingResourceController;
use App\Http\Controllers\App\BookingSettingsController;
use App\Http\Controllers\App\CommerceSettingsController;
use App\Http\Controllers\App\CrmSettingsController;
use App\Http\Controllers\App\CustomerController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\InboxController;
use App\Http\Controllers\App\LeadActionController;
use App\Http\Controllers\App\LeadController;
use App\Http\Controllers\App\MessagingSettingsController;
use App\Http\Controllers\App\OnboardingController;
use App\Http\Controllers\App\OrderActionController;
use App\Http\Controllers\App\OrderController;
use App\Http\Controllers\App\ProductCategoryController;
use App\Http\Controllers\App\ProductController;
use App\Http\Controllers\App\ServiceCategoryController;
use App\Http\Controllers\App\ServiceController;
use App\Http\Controllers\App\SettingsController;
use App\Http\Controllers\App\WebsiteController;
use App\Http\Controllers\App\WebsiteMediaController;
use App\Http\Controllers\App\WebsiteSectionController;
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

        Route::middleware('engine:commerce')->group(function () {
            Route::get('/products', [ProductController::class, 'index'])->middleware('can:products.view')->name('products.index');
            Route::get('/products/create', [ProductController::class, 'create'])->middleware('can:products.create')->name('products.create');
            Route::post('/products', [ProductController::class, 'store'])->middleware('can:products.create')->name('products.store');
            Route::post('/products/bulk', [ProductController::class, 'bulk'])->middleware('can:products.view')->name('products.bulk');
            Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->middleware('can:products.update')->name('products.edit');
            Route::delete('/products/{product}', [ProductController::class, 'destroy'])->middleware('can:products.delete')->name('products.destroy');

            Route::middleware('can:products.update')->group(function () {
                Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
                Route::post('/products/{product}/stock', [ProductController::class, 'stock'])->name('products.stock');
                Route::post('/products/{product}/image', [ProductController::class, 'uploadImage'])->middleware('throttle:60,1')->name('products.image.store');
                Route::delete('/products/{product}/image', [ProductController::class, 'removeImage'])->name('products.image.destroy');

                Route::post('/product-categories', [ProductCategoryController::class, 'store'])->name('product-categories.store');
                Route::put('/product-categories/{productCategory}', [ProductCategoryController::class, 'update'])->name('product-categories.update');
                Route::delete('/product-categories/{productCategory}', [ProductCategoryController::class, 'destroy'])->name('product-categories.destroy');
            });

            Route::get('/orders', [OrderController::class, 'index'])->middleware('can:orders.view')->name('orders.index');
            Route::get('/orders/customers', [OrderController::class, 'customers'])->middleware(['can:orders.create', 'throttle:120,1'])->name('orders.customers');
            Route::get('/orders/create', [OrderController::class, 'create'])->middleware('can:orders.create')->name('orders.create');
            Route::post('/orders', [OrderController::class, 'store'])->middleware('can:orders.create')->name('orders.store');
            Route::get('/orders/{order}', [OrderController::class, 'show'])->middleware('can:orders.view')->name('orders.show');

            Route::middleware('can:orders.update')->group(function () {
                Route::put('/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
                Route::patch('/orders/{order}/status', [OrderActionController::class, 'status'])->name('orders.status');
                Route::post('/orders/{order}/payments', [OrderActionController::class, 'storePayment'])->name('orders.payments.store');
                Route::delete('/orders/{order}/payments/{payment}', [OrderActionController::class, 'destroyPayment'])->name('orders.payments.destroy');
            });

            Route::get('/settings/commerce', [CommerceSettingsController::class, 'show'])->middleware('can:settings.view')->name('settings.commerce');
            Route::put('/settings/commerce', [CommerceSettingsController::class, 'update'])->middleware('can:settings.update')->name('settings.commerce.update');
        });

        Route::middleware('module:website')->group(function () {
            Route::middleware('can:website.view')->group(function () {
                Route::get('/website', [WebsiteController::class, 'index'])->name('website.index');
                Route::get('/website/design', [WebsiteController::class, 'design'])->name('website.design');
                Route::get('/website/details', [WebsiteController::class, 'details'])->name('website.details');
                Route::get('/website/sections/{section}/edit', [WebsiteSectionController::class, 'edit'])->name('website.sections.edit');
            });

            Route::middleware('can:website.manage')->group(function () {
                Route::put('/website/publish', [WebsiteController::class, 'publish'])->name('website.publish');
                Route::put('/website/design', [WebsiteController::class, 'updateDesign'])->name('website.design.update');
                Route::put('/website/details', [WebsiteController::class, 'updateDetails'])->name('website.details.update');
                Route::put('/website/booking', [WebsiteController::class, 'updateBooking'])->middleware('engine:booking')->name('website.booking.update');

                Route::post('/website/sections', [WebsiteSectionController::class, 'store'])->name('website.sections.store');
                Route::put('/website/sections/order', [WebsiteSectionController::class, 'reorder'])->name('website.sections.reorder');
                Route::put('/website/sections/{section}', [WebsiteSectionController::class, 'update'])->name('website.sections.update');
                Route::patch('/website/sections/{section}/toggle', [WebsiteSectionController::class, 'toggle'])->name('website.sections.toggle');
                Route::delete('/website/sections/{section}', [WebsiteSectionController::class, 'destroy'])->name('website.sections.destroy');

                Route::post('/website/media', [WebsiteMediaController::class, 'store'])->middleware('throttle:60,1')->name('website.media.store');
                Route::put('/website/media/order', [WebsiteMediaController::class, 'reorder'])->name('website.media.reorder');
                Route::patch('/website/media/{media}', [WebsiteMediaController::class, 'update'])->name('website.media.update');
                Route::delete('/website/media/{media}', [WebsiteMediaController::class, 'destroy'])->name('website.media.destroy');
            });
        });

        Route::middleware('module:messaging')->group(function () {
            Route::middleware('can:conversations.view')->group(function () {
                Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
                Route::get('/inbox/{conversation}', [InboxController::class, 'show'])->name('inbox.show');
                Route::post('/customers/{customer}/chat', [InboxController::class, 'startForCustomer'])->middleware('can:customers.view')->name('customers.chat');
                Route::post('/leads/{lead}/chat', [InboxController::class, 'startForLead'])->middleware(['module:leads', 'can:leads.view'])->name('leads.chat');
            });

            Route::middleware('can:conversations.reply')->group(function () {
                Route::post('/inbox/{conversation}/messages', [InboxController::class, 'reply'])->middleware('throttle:60,1')->name('inbox.reply');
                Route::post('/inbox/{conversation}/template', [InboxController::class, 'template'])->middleware('throttle:30,1')->name('inbox.template');
                Route::patch('/inbox/{conversation}/status', [InboxController::class, 'status'])->name('inbox.status');
                Route::patch('/inbox/{conversation}/opt-out', [InboxController::class, 'optOut'])->name('inbox.opt-out');
            });
            Route::patch('/inbox/{conversation}/assign', [InboxController::class, 'assign'])->middleware('can:conversations.assign')->name('inbox.assign');

            Route::get('/settings/messaging', [MessagingSettingsController::class, 'show'])->middleware('can:settings.view')->name('settings.messaging');
            Route::middleware('can:settings.update')->group(function () {
                Route::post('/settings/messaging/whatsapp', [MessagingSettingsController::class, 'connectWhatsApp'])->middleware('throttle:10,1')->name('settings.messaging.whatsapp');
                Route::post('/settings/messaging/instagram', [MessagingSettingsController::class, 'connectInstagram'])->middleware('throttle:10,1')->name('settings.messaging.instagram');
                Route::post('/settings/messaging/disconnect', [MessagingSettingsController::class, 'disconnect'])->name('settings.messaging.disconnect');
                Route::post('/settings/messaging/templates/sync', [MessagingSettingsController::class, 'syncTemplates'])->middleware('throttle:10,1')->name('settings.messaging.templates');
                Route::put('/settings/messaging', [MessagingSettingsController::class, 'updatePreferences'])->name('settings.messaging.update');
            });
        });

        Route::middleware('module:automation')->group(function () {
            Route::get('/automations', [AutomationController::class, 'index'])->middleware('can:automation.view')->name('automations.index');
            Route::get('/automations/create', [AutomationController::class, 'create'])->middleware('can:automation.create')->name('automations.create');
            Route::post('/automations', [AutomationController::class, 'store'])->middleware('can:automation.create')->name('automations.store');

            Route::get('/automations/runs', [AutomationRunController::class, 'index'])->middleware('can:automation.view')->name('automations.runs.index');
            Route::get('/automations/runs/{run}', [AutomationRunController::class, 'show'])->middleware('can:automation.view')->name('automations.runs.show');
            Route::post('/automations/runs/{run}/retry', [AutomationRunController::class, 'retry'])->middleware('can:automation.update')->name('automations.runs.retry');
            Route::post('/automations/runs/{run}/cancel', [AutomationRunController::class, 'cancel'])->middleware('can:automation.update')->name('automations.runs.cancel');
            Route::post('/automations/messages/{message}/retry', [AutomationRunController::class, 'retryMessage'])->middleware('can:automation.update')->name('automations.messages.retry');

            Route::get('/automations/{automation}', [AutomationController::class, 'show'])->middleware('can:automation.view')->name('automations.show');
            Route::get('/automations/{automation}/edit', [AutomationController::class, 'edit'])->middleware('can:automation.update')->name('automations.edit');
            Route::put('/automations/{automation}', [AutomationController::class, 'update'])->middleware('can:automation.update')->name('automations.update');
            Route::patch('/automations/{automation}/toggle', [AutomationController::class, 'toggle'])->middleware('can:automation.update')->name('automations.toggle');
            Route::delete('/automations/{automation}', [AutomationController::class, 'destroy'])->middleware('can:automation.delete')->name('automations.destroy');
        });
    });
});
