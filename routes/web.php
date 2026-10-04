<?php

use App\Http\Controllers\Marketing\PageController;
use Illuminate\Support\Facades\Route;

/*
| Marketing site (config('autowave.hosts.marketing')).
*/

Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/pricing', [PageController::class, 'pricing'])->name('marketing.pricing');
Route::get('/privacy', [PageController::class, 'privacy'])->name('marketing.privacy');
Route::get('/terms', [PageController::class, 'terms'])->name('marketing.terms');
Route::get('/refunds', [PageController::class, 'refunds'])->name('marketing.refunds');
Route::get('/contact', [PageController::class, 'contact'])->name('marketing.contact');
