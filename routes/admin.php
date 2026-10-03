<?php

use App\Http\Controllers\Admin\AiUsageController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TenantController;
use Illuminate\Support\Facades\Route;

/*
| Super Admin (config('autowave.hosts.admin')). Separate login; platform admins only.
*/

Route::name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthController::class, 'create'])->name('login');
        Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:admin-login')->name('login.store');
    });

    Route::middleware(['auth', 'active', 'platform.admin'])->group(function () {
        Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::post('/tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
        Route::post('/tenants/{tenant}/activate', [TenantController::class, 'activate'])->name('tenants.activate');
        Route::put('/tenants/{tenant}/storage-limit', [TenantController::class, 'updateStorageLimit'])->name('tenants.storage-limit');
        Route::get('/ai-usage', [AiUsageController::class, 'index'])->name('ai.usage');
        Route::put('/tenants/{tenant}/ai-limit', [AiUsageController::class, 'updateLimit'])->name('tenants.ai-limit');
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

        // Billing (docs/05-features/billing.md). Payments are found across tenants by id.
        Route::put('/settings/billing', [SettingsController::class, 'updateBilling'])->name('settings.billing');
        Route::get('/settings/billing/qr', [SettingsController::class, 'qr'])->name('settings.billing.qr');
        Route::post('/settings/billing/qr', [SettingsController::class, 'uploadQr'])->middleware('throttle:20,1')->name('settings.billing.qr.store');
        Route::delete('/settings/billing/qr', [SettingsController::class, 'destroyQr'])->name('settings.billing.qr.destroy');
        Route::get('/billing/payments', [BillingController::class, 'index'])->name('billing.payments');
        Route::post('/billing/payments/{paymentId}/approve', [BillingController::class, 'approve'])->whereNumber('paymentId')->name('billing.payments.approve');
        Route::post('/billing/payments/{paymentId}/reject', [BillingController::class, 'reject'])->whereNumber('paymentId')->name('billing.payments.reject');
        Route::get('/billing/payments/{paymentId}/proof', [BillingController::class, 'proof'])->whereNumber('paymentId')->name('billing.payments.proof');
        Route::get('/billing/invoices/{invoiceId}', [BillingController::class, 'invoice'])->whereNumber('invoiceId')->name('billing.invoices.show');
        Route::get('/billing/invoices/{invoiceId}/pdf', [BillingController::class, 'invoicePdf'])->whereNumber('invoiceId')->name('billing.invoices.pdf');
        Route::get('/billing/coupons', [CouponController::class, 'index'])->name('billing.coupons');
        Route::post('/billing/coupons', [CouponController::class, 'store'])->name('billing.coupons.store');
        Route::put('/billing/coupons/{coupon}', [CouponController::class, 'update'])->whereNumber('coupon')->name('billing.coupons.update');
        Route::delete('/billing/coupons/{coupon}', [CouponController::class, 'destroy'])->whereNumber('coupon')->name('billing.coupons.destroy');
        Route::post('/tenants/{tenant}/payments', [BillingController::class, 'record'])->name('tenants.payments.store');
        Route::put('/tenants/{tenant}/subscription', [BillingController::class, 'adjust'])->name('tenants.subscription');
        Route::get('/billing/plans', [PlanController::class, 'index'])->name('billing.plans');
        Route::put('/billing/plans/{plan}', [PlanController::class, 'update'])->name('billing.plans.update');
    });
});
