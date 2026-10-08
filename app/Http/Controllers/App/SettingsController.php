<?php

namespace App\Http\Controllers\App;

use App\Domain\Module\Models\Module;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteConfig;
use App\Domain\Website\Models\WebsiteSection;
use App\Http\Controllers\Controller;
use App\Support\Enums\CatalogStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only business settings. Editing arrives with the settings feature.
 */
class SettingsController extends Controller
{
    public function __invoke(Request $request, TenantContext $context): Response
    {
        $tenant = $context->tenant()->loadMissing(['businessType:id,name', 'domains']);
        $enabled = $context->enabledModules();

        return Inertia::render('business/Settings', [
            'business' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'business_type' => $tenant->businessType?->name,
                'business_type_version' => $tenant->business_type_version,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
                'locale' => $tenant->locale,
            ],
            'branding' => $context->setting('branding', []),
            'profile' => $context->setting('business_profile', []),
            'website' => $this->website(),
            'domains' => $tenant->domains->map(fn ($domain) => [
                'domain' => $domain->domain,
                'type' => $domain->type->value,
                'status' => $domain->status->value,
                'is_primary' => $domain->is_primary,
            ]),
            // Only catalogue-active modules (draft/coming-soon modules are hidden until built).
            'modules' => Module::query()->where('status', CatalogStatus::Active)->orderBy('sort_order')->get(['code', 'name', 'description'])
                ->map(fn (Module $module) => [
                    'code' => $module->code,
                    'name' => $module->name,
                    'description' => $module->description,
                    'enabled' => in_array($module->code, $enabled, true),
                ]),
            'canUpdate' => $request->user()->can('settings.update'),
        ]);
    }

    /** @return ?array<string, mixed> */
    private function website(): ?array
    {
        $config = WebsiteConfig::query()->with('template:id,code,name')->first();

        if (! $config) {
            return null;
        }

        return [
            'template' => $config->template->name,
            'status' => $config->status,
            'sections' => WebsiteSection::query()->orderBy('sort_order')->get(['type', 'enabled'])
                ->map(fn (WebsiteSection $section) => ['type' => $section->type, 'enabled' => $section->enabled]),
        ];
    }
}
