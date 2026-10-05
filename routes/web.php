<?php

use App\Http\Controllers\Marketing\DemoRequestController;
use App\Http\Controllers\Marketing\PageController;
use App\Http\Controllers\SeoController;
use Illuminate\Support\Facades\Route;

/*
| Marketing site (config('autowave.hosts.marketing')).
*/

Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/for/{industry}', [PageController::class, 'industry'])->name('marketing.industry');
Route::get('/pricing', [PageController::class, 'pricing'])->name('marketing.pricing');
Route::get('/demo', [PageController::class, 'demo'])->name('marketing.demo');
Route::post('/demo', DemoRequestController::class)->middleware('throttle:website-enquiry')->name('marketing.demo.store');
Route::get('/privacy', [PageController::class, 'privacy'])->name('marketing.privacy');
Route::get('/terms', [PageController::class, 'terms'])->name('marketing.terms');
Route::get('/refunds', [PageController::class, 'refunds'])->name('marketing.refunds');
Route::get('/contact', [PageController::class, 'contact'])->name('marketing.contact');

Route::get('/robots.txt', [SeoController::class, 'marketingRobots'])->name('marketing.robots');
Route::get('/sitemap.xml', [SeoController::class, 'marketingSitemap'])->name('marketing.sitemap');
