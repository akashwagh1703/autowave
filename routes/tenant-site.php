<?php

use App\Http\Controllers\SeoController;
use App\Http\Controllers\Website\BookingController;
use App\Http\Controllers\Website\EnquiryController;
use App\Http\Controllers\Website\HomeController;
use App\Http\Controllers\Website\ManageBookingController;
use App\Http\Controllers\Website\ReservationController;
use App\Http\Controllers\Website\ShopController;
use Illuminate\Support\Facades\Route;

/*
| Public tenant websites: every host that is not a platform host. Registered
| last so platform host routes always win. The tenant comes from the domain.
*/

Route::get('/', HomeController::class)->name('tenant.home');
Route::get('/preview', HomeController::class)->name('tenant.preview');
Route::get('/robots.txt', [SeoController::class, 'siteRobots'])->name('tenant.robots');
Route::get('/sitemap.xml', [SeoController::class, 'siteSitemap'])->name('tenant.sitemap');

Route::middleware('site.live')->group(function () {
    Route::post('/enquiry', EnquiryController::class)->middleware('throttle:website-enquiry')->name('tenant.enquiry');
    Route::get('/booking/slots', [BookingController::class, 'slots'])->middleware('throttle:website-slots')->name('tenant.booking.slots');
    Route::post('/booking', [BookingController::class, 'store'])->middleware('throttle:website-booking')->name('tenant.booking.store');
    Route::middleware('throttle:website-booking')->prefix('booking/manage/{appointment}')->group(function () {
        Route::get('/', [ManageBookingController::class, 'show'])->middleware('signed:relative')->name('tenant.booking.manage');
        Route::get('/slots', [ManageBookingController::class, 'slots'])->name('tenant.booking.manage.slots');
        Route::post('/cancel', [ManageBookingController::class, 'cancel'])->name('tenant.booking.manage.cancel');
        Route::post('/reschedule', [ManageBookingController::class, 'reschedule'])->name('tenant.booking.manage.reschedule');
    });
    Route::post('/cart/quote', [ShopController::class, 'quote'])->middleware('throttle:website-cart')->name('tenant.cart.quote');
    Route::post('/orders', [ShopController::class, 'store'])->middleware('throttle:website-order')->name('tenant.orders.store');
    Route::get('/reservations/slots', [ReservationController::class, 'slots'])->middleware('throttle:website-slots')->name('tenant.reservations.slots');
    Route::post('/reservations', [ReservationController::class, 'store'])->middleware('throttle:website-reservation')->name('tenant.reservations.store');
});
