<?php

namespace App\Providers;

use App\Domain\RBAC\Support\PermissionCatalog;
use App\Domain\RBAC\Support\PermissionResolver;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: a fresh instance per request and per queued job, so tenant state never leaks.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(PermissionResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (PermissionCatalog::keys() as $permission) {
            Gate::define($permission, fn (User $user) => $this->app->make(PermissionResolver::class)->allows($user, $permission));
        }

        // Numeric ids only, so a malformed URL is a 404 rather than a database error.
        Route::patterns([
            'lead' => '[0-9]+',
            'customer' => '[0-9]+',
            'service' => '[0-9]+',
            'category' => '[0-9]+',
            'bookingResource' => '[0-9]+',
            'timeOff' => '[0-9]+',
            'appointment' => '[0-9]+',
        ]);

        // Business creation attempts (including validation failures) per user.
        RateLimiter::for('onboarding', fn (Request $request) => Limit::perHour(20)->by('onboarding|'.($request->user()?->getKey() ?? $request->ip())));
    }
}
