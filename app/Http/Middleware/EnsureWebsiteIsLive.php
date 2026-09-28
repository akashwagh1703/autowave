<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public website endpoints (forms, booking) answer only while the tenant's website module is on
 * and the website is published; otherwise 404, like the site itself.
 */
class EnsureWebsiteIsLive
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->context->hasModule('website') && WebsiteConfig::query()->first()?->isPublished(), 404);

        return $next($request);
    }
}
