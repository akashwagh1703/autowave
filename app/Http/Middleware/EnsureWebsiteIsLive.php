<?php

namespace App\Http\Middleware;

use App\Domain\Billing\Support\Entitlements;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public website endpoints (forms, booking) answer only while the tenant's website module is on
 * and the website is published; otherwise 404, like the site itself. 503 while the business is
 * locked for an unpaid plan.
 */
class EnsureWebsiteIsLive
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Entitlements $entitlements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->context->hasModule('website') && WebsiteConfig::query()->first()?->isPublished(), 404);
        abort_unless($this->entitlements->websiteOnline($this->context->tenant()), 503);

        return $next($request);
    }
}
