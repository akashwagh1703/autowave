<?php

use Illuminate\Support\Facades\Route;

/*
| Marketing site (config('autowave.hosts.marketing')).
*/

Route::get('/', fn () => inertia('Welcome', ['appUrl' => rtrim(config('app.url'), '/')]))->name('home');
