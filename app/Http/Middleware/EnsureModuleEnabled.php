<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `module:leads` — 404 unless the current tenant has the module enabled. A disabled module
 * behaves as if the feature does not exist.
 */
class EnsureModuleEnabled
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        foreach ($modules as $module) {
            abort_unless($this->context->check() && $this->context->hasModule($module), 404);
        }

        return $next($request);
    }
}
