<?php

namespace App\Http\Controllers\Website;

use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Placeholder public website for a tenant. The section-based website builder is a later phase.
 */
class HomeController extends Controller
{
    public function __invoke(TenantContext $context): Response
    {
        abort_unless($context->hasModule('website'), 404);

        $tenant = $context->tenant()->loadMissing('businessType:id,name');

        return Inertia::render('website/Home', [
            'business' => [
                'name' => $context->setting('branding', [])['business_name'] ?? $tenant->name,
                'business_type' => $tenant->businessType?->name,
                'primary_color' => $context->setting('branding', [])['primary_color'] ?? null,
            ],
            'sections' => $context->setting('website_sections', []),
        ]);
    }
}
