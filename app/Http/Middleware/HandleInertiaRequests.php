<?php

namespace App\Http\Middleware;

use App\Domain\Booking\Support\BookingSettings;
use App\Domain\RBAC\Support\PermissionResolver;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * Only expose data that is safe for the browser. Never share secrets, tokens,
     * or another tenant's data here. Tenant props are closures because the tenant
     * is resolved by route middleware that runs after this one.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
            ],
            'auth' => [
                'user' => fn () => $request->user()?->only(['id', 'name', 'email']),
            ],
            'tenant' => function () {
                $context = app(TenantContext::class);
                $tenant = $context->get();

                if (! $tenant) {
                    return null;
                }

                $engines = $context->enabledEngines();

                return [
                    ...$tenant->only(['id', 'name', 'slug', 'timezone', 'currency', 'locale']),
                    'modules' => $context->enabledModules(),
                    'engines' => $engines,
                    'resource_label' => in_array('booking', $engines, true) ? app(BookingSettings::class)->resourceLabels() : null,
                ];
            },
            'permissions' => function () use ($request) {
                $user = $request->user();

                return $user && app(TenantContext::class)->check()
                    ? app(PermissionResolver::class)->permissionsFor($user)
                    : [];
            },
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
