<?php

namespace App\Http\Controllers\App;

use App\Domain\Module\Actions\UpdateTenantModules;
use App\Domain\Module\Models\Module;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteConfig;
use App\Domain\Website\Models\WebsiteSection;
use App\Http\Controllers\Controller;
use App\Support\Enums\CatalogStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Business settings: profile overview and post-onboarding module toggles. */
class SettingsController extends Controller
{
    public function __invoke(Request $request, TenantContext $context): Response
    {
        $tenant = $context->tenant()->loadMissing(['businessType:id,name', 'businessType.engines', 'businessType.modules', 'domains']);
        $enabled = $context->enabledModules();

        $required = $tenant->businessType?->engines
            ->flatMap->requiredModuleCodes()
            ->unique()
            ->values()
            ->all() ?? [];

        $recommended = $tenant->businessType?->modules
            ->filter(fn (Module $module) => $module->pivot->enabled && $module->isActive())
            ->pluck('code')
            ->all() ?? [];

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
            'modules' => Module::query()
                ->where('status', CatalogStatus::Active)
                ->with('dependencies:id,code')
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Module $module) => [
                    'code' => $module->code,
                    'name' => $module->name,
                    'description' => $module->description,
                    'type' => $module->type,
                    'depends_on' => $module->dependencies->pluck('code')->values()->all(),
                    'enabled' => in_array($module->code, $enabled, true),
                    'recommended' => in_array($module->code, $recommended, true),
                    'required' => in_array($module->code, $required, true),
                ]),
            'canUpdate' => $request->user()->can('settings.update'),
        ]);
    }

    public function updateModules(Request $request, TenantContext $context, UpdateTenantModules $update): RedirectResponse
    {
        $active = Module::query()->where('status', CatalogStatus::Active)->pluck('code')->all();

        $validated = $request->validate([
            'modules' => ['present', 'array'],
            'modules.*' => ['string', 'distinct', Rule::in($active)],
        ]);

        $result = $update->handle($context->tenant(), array_values($validated['modules']));

        $message = __('Modules updated.');
        if ($result['enabled_now'] !== [] || $result['disabled_now'] !== []) {
            $parts = [];
            if ($result['enabled_now'] !== []) {
                $parts[] = __('turned on :list', ['list' => implode(', ', $result['enabled_now'])]);
            }
            if ($result['disabled_now'] !== []) {
                $parts[] = __('turned off :list', ['list' => implode(', ', $result['disabled_now'])]);
            }
            $message = __('Modules updated (:changes).', ['changes' => implode('; ', $parts)]);
        }

        return back()->with('success', $message);
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
