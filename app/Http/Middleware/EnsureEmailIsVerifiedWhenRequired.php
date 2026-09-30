<?php

namespace App\Http\Middleware;

use App\Domain\Platform\Support\PlatformSettings;
use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;

/** The `verified` middleware, skipped while a platform admin has switched email verification off. */
class EnsureEmailIsVerifiedWhenRequired extends EnsureEmailIsVerified
{
    public function handle($request, Closure $next, $redirectToRoute = null)
    {
        if (! app(PlatformSettings::class)->requireEmailVerification()) {
            return $next($request);
        }

        return parent::handle($request, $next, $redirectToRoute);
    }
}
