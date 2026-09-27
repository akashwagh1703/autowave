<?php

namespace App\Providers;

use App\Domain\RBAC\Support\PermissionCatalog;
use App\Domain\RBAC\Support\PermissionResolver;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
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
    }
}
