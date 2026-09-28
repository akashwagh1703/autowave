<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `engine:booking` — 404 unless the current tenant has the engine enabled. A disabled engine
 * behaves as if the feature does not exist.
 */
class EnsureEngineEnabled
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string ...$engines): Response
    {
        foreach ($engines as $engine) {
            abort_unless($this->context->check() && $this->context->hasEngine($engine), 404);
        }

        return $next($request);
    }
}
