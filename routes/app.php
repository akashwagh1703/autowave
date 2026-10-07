<?php

use App\Http\Controllers\App\AiController;
use App\Http\Controllers\App\AiSettingsController;
use App\Http\Controllers\App\AppointmentActionController;
use App\Http\Controllers\App\AppointmentController;
use App\Http\Controllers\App\AssistantController;
use App\Http\Controllers\App\AttachmentController;
use App\Http\Controllers\App\AutomationController;
use App\Http\Controllers\App\AutomationRunController;
use App\Http\Controllers\App\BatchController;
use App\Http\Controllers\App\BillingController;
use App\Http\Controllers\App\BookingResourceController;
use App\Http\Controllers\App\BookingSettingsController;
use App\Http\Controllers\App\ChatbotSettingsController;
use App\Http\Controllers\App\CommerceSettingsController;
use App\Http\Controllers\App\CouponController;
use App\Http\Controllers\App\CourseController;
use App\Http\Controllers\App\CrmSettingsController;
use App\Http\Controllers\App\CustomerController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\DemoClassController;
use App\Http\Controllers\App\DiningTableController;
use App\Http\Controllers\App\EducationSettingsController;
use App\Http\Controllers\App\FeeController;
use App\Http\Controllers\App\FoodSettingsController;
use App\Http\Controllers\App\InboxController;
use App\Http\Controllers\App\KitchenController;
use App\Http\Controllers\App\LeadActionController;
use App\Http\Controllers\App\LeadController;
use App\Http\Controllers\App\MessagingSettingsController;
use App\Http\Controllers\App\OnboardingController;
use App\Http\Controllers\App\OrderActionController;
use App\Http\Controllers\App\OrderController;
use App\Http\Controllers\App\ProductCategoryController;
use App\Http\Controllers\App\ProductController;
use App\Http\Controllers\App\ReservationController;
use App\Http\Controllers\App\ServiceCategoryController;
use App\Http\Controllers\App\ServiceController;
use App\Http\Controllers\App\SettingsController;
use App\Http\Controllers\App\StudentController;
use App\Http\Controllers\App\WebsiteController;
use App\Http\Controllers\App\WebsiteMediaController;
use App\Http\Controllers\App\WebsiteSectionController;
use App\Http\Controllers\App\WorkspaceController;
use App\Http\Controllers\SeoController;
use Illuminate\Support\Facades\Route;

/*
| Business app (config('autowave.hosts.app')). Authentication routes (login,
| register, password reset, email verification) are registered by Fortify.
*/

Route::redirect('/', '/dashboard');
Route::get('/robots.txt', [SeoController::class, 'closedRobots'])->name('robots');

Route::middleware(['auth', 'active', 'verified'])->group(function () {
    Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('/workspaces/{tenant}/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');

    Route::get('/onboarding', [OnboardingController::class, 'create'])->name('onboarding.create');
    Route::get('/onboarding/slug', [OnboardingController::class, 'slug'])->middleware('throttle:60,1')->name('onboarding.slug');
    Route::post('/onboarding', [OnboardingController::class, 'store'])->middleware('throttle:onboarding')->name('onboarding.store');

    Route::middleware(['tenant.member', 'subscription'])->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/settings', SettingsController::class)->middleware('can:settings.view')->name('settings');

        // Billing (docs/05-features/billing.md): always reachable, even while read-only or locked.
        Route::middleware('can:billing.view')->group(function () {
            Route::get('/settings/billing', [BillingController::class, 'show'])->name('billing.show');
            Route::get('/settings/billing/invoices/{invoice}', [BillingController::class, 'invoice'])->name('billing.invoices.show');
            Route::get('/settings/billing/invoices/{invoice}/pdf', [BillingController::class, 'invoicePdf'])->middleware('throttle:30,1')->name('billing.invoices.pdf');
        });
        Route::middleware('can:billing.manage')->group(function () {
            Route::get('/settings/billing/quote', [BillingController::class, 'quote'])->middleware('throttle:60,1')->name('billing.quote');
            Route::get('/settings/billing/qr', [BillingController::class, 'qr'])->middleware('throttle:60,1')->name('billing.qr');
            Route::post('/settings/billing/payments', [BillingController::class, 'pay'])->middleware('throttle:billing-pay')->name('billing.pay');
            Route::post('/settings/billing/payments/{billingPayment}/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
            Route::post('/settings/billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:billing-checkout')->name('billing.checkout');
            Route::post('/settings/billing/checkout/{billingPayment}/confirm', [BillingController::class, 'confirm'])->middleware('throttle:30,1')->name('billing.checkout.confirm');
            Route::post('/settings/billing/activate', [BillingController::class, 'activate'])->middleware('throttle:billing-pay')->name('billing.activate');
        });

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

        // Outside the module groups: student pages show their customer's documents too.
        Route::post('/customers/{customer}/attachments', [AttachmentController::class, 'storeForCustomer'])->middleware(['can:documents.manage', 'throttle:60,1'])->name('customers.attachments.store');
        Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
        Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

        Route::middleware('engine:service')->group(function () {
            Route::get('/services', [ServiceController::class, 'index'])->middleware('can:services.view')->name('services.index');

            Route::middleware('can:services.manage')->group(function () {
                Route::get('/services/create', [ServiceController::class, 'create'])->name('services.create');
                Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
                Route::post('/services/bulk', [ServiceController::class, 'bulk'])->name('services.bulk');
                Route::get('/services/{service}/edit', [ServiceController::class, 'edit'])->name('services.edit');
                Route::put('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
                Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
                Route::post('/services/{service}/image', [ServiceController::class, 'uploadImage'])->middleware('throttle:60,1')->name('services.image.store');
                Route::delete('/services/{service}/image', [ServiceController::class, 'removeImage'])->name('services.image.destroy');
                Route::post('/services/{service}/attachments', [AttachmentController::class, 'storeForService'])->middleware('throttle:60,1')->name('services.attachments.store');

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
            Route::post('/appointments/{appointment}/payments', [AppointmentActionController::class, 'storePayment'])->middleware('can:appointments.update')->name('appointments.payments.store');
            Route::delete('/appointments/{appointment}/payments/{payment}', [AppointmentActionController::class, 'destroyPayment'])->middleware('can:appointments.update')->name('appointments.payments.destroy');

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
                Route::post('/products/{product}/attachments', [AttachmentController::class, 'storeForProduct'])->middleware('throttle:60,1')->name('products.attachments.store');

                Route::post('/product-categories', [ProductCategoryController::class, 'store'])->name('product-categories.store');
                Route::put('/product-categories/{productCategory}', [ProductCategoryController::class, 'update'])->name('product-categories.update');
                Route::delete('/product-categories/{productCategory}', [ProductCategoryController::class, 'destroy'])->name('product-categories.destroy');
            });

            Route::get('/orders', [OrderController::class, 'index'])->middleware('can:orders.view')->name('orders.index');
            Route::get('/orders/customers', [OrderController::class, 'customers'])->middleware(['can:orders.create', 'throttle:120,1'])->name('orders.customers');
            Route::post('/orders/coupon', [OrderController::class, 'coupon'])->middleware(['module:offers', 'can:orders.create', 'throttle:60,1'])->name('orders.coupon');
            Route::get('/orders/create', [OrderController::class, 'create'])->middleware('can:orders.create')->name('orders.create');
            Route::post('/orders', [OrderController::class, 'store'])->middleware('can:orders.create')->name('orders.store');
            Route::get('/orders/{order}', [OrderController::class, 'show'])->middleware('can:orders.view')->name('orders.show');

            Route::middleware('can:orders.update')->group(function () {
                Route::put('/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
                Route::patch('/orders/{order}/status', [OrderActionController::class, 'status'])->name('orders.status');
                Route::post('/orders/{order}/payments', [OrderActionController::class, 'storePayment'])->name('orders.payments.store');
                Route::delete('/orders/{order}/payments/{payment}', [OrderActionController::class, 'destroyPayment'])->name('orders.payments.destroy');
                Route::post('/orders/{order}/items', [OrderController::class, 'addItems'])->middleware('engine:food')->name('orders.items.store');
            });

            Route::middleware('module:offers')->group(function () {
                Route::get('/offers', [CouponController::class, 'index'])->middleware('can:offers.view')->name('offers.index');
                Route::middleware('can:offers.manage')->group(function () {
                    Route::post('/offers', [CouponController::class, 'store'])->name('offers.store');
                    Route::put('/offers/{coupon}', [CouponController::class, 'update'])->name('offers.update');
                    Route::delete('/offers/{coupon}', [CouponController::class, 'destroy'])->name('offers.destroy');
                });
            });

            Route::get('/settings/commerce', [CommerceSettingsController::class, 'show'])->middleware('can:settings.view')->name('settings.commerce');
            Route::put('/settings/commerce', [CommerceSettingsController::class, 'update'])->middleware('can:settings.update')->name('settings.commerce.update');
        });

        // Coaching (ADR-020): courses, batches, students (enrolments), fees, attendance and demo classes.
        Route::middleware('engine:education')->group(function () {
            Route::get('/courses', [CourseController::class, 'index'])->middleware('can:courses.view')->name('courses.index');

            Route::middleware('can:courses.manage')->group(function () {
                Route::post('/courses', [CourseController::class, 'store'])->name('courses.store');
                Route::put('/courses/{course}', [CourseController::class, 'update'])->name('courses.update');
                Route::delete('/courses/{course}', [CourseController::class, 'destroy'])->name('courses.destroy');
                Route::post('/courses/{course}/image', [CourseController::class, 'uploadImage'])->middleware('throttle:60,1')->name('courses.image.store');
                Route::delete('/courses/{course}/image', [CourseController::class, 'removeImage'])->name('courses.image.destroy');
                Route::post('/courses/{course}/attachments', [AttachmentController::class, 'storeForCourse'])->middleware('throttle:60,1')->name('courses.attachments.store');

                Route::get('/batches/create', [BatchController::class, 'create'])->name('batches.create');
                Route::post('/batches', [BatchController::class, 'store'])->name('batches.store');
                Route::get('/batches/{batch}/edit', [BatchController::class, 'edit'])->name('batches.edit');
                Route::put('/batches/{batch}', [BatchController::class, 'update'])->name('batches.update');
                Route::delete('/batches/{batch}', [BatchController::class, 'destroy'])->name('batches.destroy');
            });

            Route::get('/batches/{batch}', [BatchController::class, 'show'])->middleware('can:courses.view')->name('batches.show');
            Route::post('/batches/{batch}/attendance', [BatchController::class, 'attendance'])->middleware('can:students.attendance')->name('batches.attendance');

            Route::get('/students', [StudentController::class, 'index'])->middleware('can:students.view')->name('students.index');
            Route::get('/students/lookup', [StudentController::class, 'lookup'])->middleware(['can:students.admit', 'throttle:120,1'])->name('students.lookup');
            Route::get('/students/admit', [StudentController::class, 'create'])->middleware('can:students.admit')->name('students.create');
            Route::post('/students', [StudentController::class, 'store'])->middleware('can:students.admit')->name('students.store');
            Route::get('/students/{enrolment}', [StudentController::class, 'show'])->middleware('can:students.view')->name('students.show');
            Route::put('/students/{enrolment}', [StudentController::class, 'update'])->middleware('can:students.update')->name('students.update');
            Route::patch('/students/{enrolment}/status', [StudentController::class, 'status'])->middleware('can:students.update')->name('students.status');
            Route::put('/students/{enrolment}/fees', [StudentController::class, 'fees'])->middleware('can:fees.manage')->name('students.fees');
            Route::post('/students/{enrolment}/payments', [StudentController::class, 'storePayment'])->middleware('can:fees.collect')->name('students.payments.store');
            Route::delete('/students/{enrolment}/payments/{payment}', [StudentController::class, 'destroyPayment'])->middleware('can:fees.collect')->name('students.payments.destroy');

            Route::get('/fees', [FeeController::class, 'index'])->middleware('can:fees.view')->name('fees.index');

            Route::get('/demos', [DemoClassController::class, 'index'])->middleware('can:students.view')->name('demos.index');
            Route::post('/leads/{lead}/demos', [DemoClassController::class, 'store'])->middleware(['module:leads', 'can:students.admit'])->name('demos.store');
            Route::patch('/demos/{demo}', [DemoClassController::class, 'status'])->middleware('can:students.admit')->name('demos.status');

            Route::get('/settings/education', [EducationSettingsController::class, 'show'])->middleware('can:settings.view')->name('settings.education');
            Route::put('/settings/education', [EducationSettingsController::class, 'update'])->middleware('can:settings.update')->name('settings.education.update');
        });

        // Cafe / restaurant (ADR-020): tables, reservations and the kitchen screen. The menu and
        // dine-in orders are the commerce products and orders.
        Route::middleware('engine:food')->group(function () {
            Route::get('/tables', [DiningTableController::class, 'index'])->middleware('can:reservations.view')->name('tables.index');
            Route::middleware('can:reservations.manage')->group(function () {
                Route::post('/tables', [DiningTableController::class, 'store'])->name('tables.store');
                Route::put('/tables/{table}', [DiningTableController::class, 'update'])->name('tables.update');
                Route::delete('/tables/{table}', [DiningTableController::class, 'destroy'])->name('tables.destroy');
            });

            Route::get('/reservations', [ReservationController::class, 'index'])->middleware('can:reservations.view')->name('reservations.index');
            Route::get('/reservations/customers', [ReservationController::class, 'customers'])->middleware(['can:reservations.manage', 'throttle:120,1'])->name('reservations.customers');
            Route::get('/reservations/{reservation}', [ReservationController::class, 'show'])->middleware('can:reservations.view')->name('reservations.show');
            Route::middleware('can:reservations.manage')->group(function () {
                Route::post('/reservations', [ReservationController::class, 'store'])->name('reservations.store');
                Route::put('/reservations/{reservation}', [ReservationController::class, 'update'])->name('reservations.update');
                Route::patch('/reservations/{reservation}/status', [ReservationController::class, 'status'])->name('reservations.status');
            });

            Route::get('/kitchen', [KitchenController::class, 'index'])->middleware(['engine:commerce', 'can:orders.view'])->name('kitchen.index');
            Route::post('/kitchen/{order}/ready', [KitchenController::class, 'ready'])->middleware(['engine:commerce', 'can:orders.update'])->name('kitchen.ready');

            Route::get('/settings/food', [FoodSettingsController::class, 'show'])->middleware('can:settings.view')->name('settings.food');
            Route::put('/settings/food', [FoodSettingsController::class, 'update'])->middleware('can:settings.update')->name('settings.food.update');
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
                Route::post('/website/sections/{section}/attachments', [AttachmentController::class, 'storeForWebsiteSection'])->middleware('throttle:60,1')->name('website.sections.attachments.store');

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

            Route::get('/settings/whatsapp-assistant', [ChatbotSettingsController::class, 'show'])->middleware('can:settings.view')->name('settings.chatbot');
            Route::put('/settings/whatsapp-assistant', [ChatbotSettingsController::class, 'update'])->middleware('can:settings.update')->name('settings.chatbot.update');
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

        // AI (ADR-019): drafts and suggestions only; people send and save.
        Route::middleware('module:ai')->group(function () {
            Route::get('/settings/ai', [AiSettingsController::class, 'show'])->middleware('can:settings.view')->name('settings.ai');
            Route::put('/settings/ai', [AiSettingsController::class, 'update'])->middleware('can:settings.update')->name('settings.ai.update');

            Route::middleware(['can:ai.use', 'throttle:ai'])->group(function () {
                Route::get('/assistant', [AssistantController::class, 'index'])->withoutMiddleware('throttle:ai')->name('assistant');
                Route::post('/assistant/ask', [AssistantController::class, 'ask'])->middleware('can:ai.assistant')->name('assistant.ask');
                Route::post('/ai/write', [AiController::class, 'write'])->name('ai.write');

                Route::middleware('module:messaging')->group(function () {
                    Route::post('/ai/conversations/{conversation}/summary', [AiController::class, 'conversationSummary'])->middleware('can:conversations.view')->name('ai.conversations.summary');
                    Route::middleware('can:conversations.reply')->group(function () {
                        Route::post('/ai/conversations/{conversation}/reply', [AiController::class, 'reply'])->name('ai.conversations.reply');
                        Route::post('/ai/conversations/{conversation}/drafts/{draft}/dismiss', [AiController::class, 'dismissDraft'])->withoutMiddleware('throttle:ai')->name('ai.conversations.drafts.dismiss');
                    });
                });

                Route::middleware('module:leads')->group(function () {
                    Route::post('/ai/leads/{lead}/summary', [AiController::class, 'leadSummary'])->middleware('can:leads.view')->name('ai.leads.summary');
                    Route::middleware('can:leads.update')->group(function () {
                        Route::post('/ai/leads/{lead}/extract', [AiController::class, 'extract'])->name('ai.leads.extract');
                        Route::post('/ai/leads/{lead}/suggestions/apply', [AiController::class, 'applySuggestions'])->withoutMiddleware('throttle:ai')->name('ai.leads.suggestions.apply');
                        Route::post('/ai/leads/{lead}/suggestions/dismiss', [AiController::class, 'dismissSuggestions'])->withoutMiddleware('throttle:ai')->name('ai.leads.suggestions.dismiss');
                    });
                });

                Route::post('/ai/customers/{customer}/summary', [AiController::class, 'customerSummary'])->middleware(['module:customers', 'can:customers.view'])->name('ai.customers.summary');
            });
        });
    });
});
