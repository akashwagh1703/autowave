<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureEngineEnabled;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureWebsiteIsLive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenantFromDomain;
use App\Http\Middleware\ResolveTenantFromMembership;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/*
| Routing is host-based (ADR-007): admin, app and marketing hosts are bound
| explicitly; every other host is a tenant website, registered last.
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('throttle:meta-webhooks')->domain(config('autowave.hosts.app'))->group(base_path('routes/webhooks.php'));
            Route::middleware('web')->domain(config('autowave.hosts.admin'))->group(base_path('routes/admin.php'));
            Route::middleware('web')->domain(config('autowave.hosts.app'))->group(base_path('routes/app.php'));
            Route::middleware('web')->domain(config('autowave.hosts.marketing'))->group(base_path('routes/web.php'));

            Route::domain('www.'.config('autowave.root_domain'))->group(function (): void {
                Route::get('/{path?}', fn (Request $request) => redirect()->away(
                    $request->getScheme().'://'.config('autowave.hosts.marketing').$request->getRequestUri(), 301
                ))->where('path', '.*');
            });

            Route::middleware(['web', 'tenant.site'])->group(base_path('routes/tenant-site.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'tenant.site' => ResolveTenantFromDomain::class,
            'site.live' => EnsureWebsiteIsLive::class,
            'tenant.member' => ResolveTenantFromMembership::class,
            'module' => EnsureModuleEnabled::class,
            'engine' => EnsureEngineEnabled::class,
            'active' => EnsureAccountIsActive::class,
            'platform.admin' => EnsurePlatformAdmin::class,
        ]);

        // Route model binding (in the web group) must run after the tenant is resolved, or
        // tenant-scoped models such as {lead} could never be found.
        foreach ([EnsureAccountIsActive::class, EnsureEmailIsVerified::class, ResolveTenantFromMembership::class, ResolveTenantFromDomain::class] as $tenantMiddleware) {
            $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: $tenantMiddleware);
        }

        $middleware->redirectGuestsTo(fn (Request $request) => $request->getHost() === config('autowave.hosts.admin')
            ? route('admin.login')
            : route('login'));

        $middleware->redirectUsersTo(fn (Request $request) => $request->getHost() === config('autowave.hosts.admin')
            ? route('admin.dashboard')
            : route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();

            if (($request->expectsJson() && ! $request->header('X-Inertia')) || $request->is('webhooks/*')) {
                return $response;
            }

            if ($status === 419) {
                return back()->with('error', __('The page expired. Please try again.'));
            }

            // Rate-limited form submissions: show the message on the form instead of an error page.
            if ($status === 429 && $request->header('X-Inertia') && ! $request->isMethod('GET')) {
                $message = __('Too many attempts. Please wait a few minutes and try again.');

                return back()->withErrors(['throttle' => $message])->with('error', $message);
            }

            $rendered = [403, 404];

            if (! app()->hasDebugModeEnabled()) {
                $rendered = [...$rendered, 500, 503];
            }

            if (in_array($status, $rendered, true)) {
                return Inertia::render('Error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
