<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out users whose account was suspended after they logged in.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $login = $request->getHost() === config('autowave.hosts.admin') ? 'admin.login' : 'login';

            return redirect()->route($login)->with('error', __('Your account is suspended. Please contact support.'));
        }

        return $next($request);
    }
}
