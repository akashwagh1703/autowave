<?php

namespace App\Http\Controllers\Website;

use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteConfig;
use App\Domain\Website\Models\WebsiteSection;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public tenant website: the chosen template renders the tenant's enabled sections (ADR-012).
 */
class HomeController extends Controller
{
    public function __invoke(TenantContext $context): Response
    {
        abort_unless($context->hasModule('website'), 404);

        $config = WebsiteConfig::query()->with('template')->first();

        abort_unless($config?->isPublished(), 404);

        $tenant = $context->tenant()->loadMissing('businessType:id,name');
        $branding = $context->setting('branding', []);
        $profile = $context->setting('business_profile', []);

        return Inertia::render('website/Home', [
            'business' => [
                'name' => $branding['business_name'] ?? $tenant->name,
                'business_type' => $tenant->businessType?->name,
                'tagline' => $branding['tagline'] ?? null,
                'primary_color' => $config->theme['primary_color'] ?? $branding['primary_color'] ?? null,
            ],
            'template' => [
                'code' => $config->template->code,
                ...$config->template->theme(),
            ],
            'seo' => $config->seo ?? [],
            'contact' => [
                'phone' => $profile['phone'] ?? null,
                'email' => $profile['email'] ?? null,
                'city' => $profile['city'] ?? null,
                'address' => $profile['address'] ?? null,
            ],
            'sections' => WebsiteSection::query()
                ->where('enabled', true)
                ->orderBy('sort_order')
                ->get(['id', 'type', 'configuration'])
                ->map(fn (WebsiteSection $section) => [
                    'id' => $section->id,
                    'type' => $section->type,
                    'configuration' => $section->configuration ?? [],
                ]),
        ]);
    }
}
