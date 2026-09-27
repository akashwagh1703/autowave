<?php

namespace App\Http\Middleware;

use App\Domain\Domain\Services\DomainResolver;
use App\Domain\Tenant\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public tenant websites: the Host header selects the tenant (ADR-007).
 * Unknown, disabled or suspended hosts get a 404 so nothing is leaked.
 */
class ResolveTenantFromDomain
{
    public function __construct(
        private readonly DomainResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolver->resolve($request->getHost());

        abort_if($tenant === null, 404);

        $this->context->set($tenant);

        return $next($request);
    }
}
