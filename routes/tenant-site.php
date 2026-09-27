<?php

use App\Http\Controllers\Website\HomeController;
use Illuminate\Support\Facades\Route;

/*
| Public tenant websites: every host that is not a platform host. Registered
| last so platform host routes always win. The tenant comes from the domain.
*/

Route::get('/', HomeController::class)->name('tenant.home');
