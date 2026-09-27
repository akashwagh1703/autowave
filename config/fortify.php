<?php

use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Fortify (ADR-010)
|--------------------------------------------------------------------------
|
| Fortify provides the backend for business-app authentication on the app host
| only. Super Admin authentication is separate (admin host, Admin\AuthController).
| React pages are registered in App\Providers\FortifyServiceProvider.
|
*/

return [

    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    'lowercase_usernames' => true,

    'home' => '/dashboard',

    'redirects' => [
        'logout' => '/login',
    ],

    'prefix' => '',

    // Must match config('autowave.hosts.app').
    'domain' => env('AUTOWAVE_APP_HOST', 'app.autowave.localhost'),

    'middleware' => ['web'],

    'limiters' => [
        'login' => 'login',
    ],

    'views' => true,

    /*
    | Two-factor authentication and passkeys are supported by Fortify but are
    | intentionally not enabled in V1 (master prompt §8). Enabling them requires
    | the corresponding Fortify migrations and UI.
    */
    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
    ],

];
