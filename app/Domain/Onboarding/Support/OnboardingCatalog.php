<?php

namespace App\Domain\Onboarding\Support;

use App\Domain\Business\Models\BusinessType;
use App\Domain\Domain\Support\Hostname;
use App\Domain\Engine\Models\Engine;
use App\Domain\Module\Models\Module;
use App\Domain\Website\Models\WebsiteTemplate;
use App\Support\Enums\CatalogStatus;
use Illuminate\Support\Collection;

/**
 * What a business owner may choose from during onboarding. Everything is read from
 * the catalogue tables, so adding a business type, module or template needs no code change.
 */
class OnboardingCatalog
{
    /**
     * Public, active business types, newest version of each code.
     *
     * @return Collection<string, BusinessType>
     */
    public function businessTypes(): Collection
    {
        return BusinessType::query()
            ->active()
            ->where('is_public', true)
            ->with(['engines', 'modules'])
            ->orderBy('sort_order')
            ->get()
            ->groupBy('code')
            ->map(fn (Collection $versions) => $versions->sortByDesc(fn (BusinessType $type) => $type->version, SORT_NATURAL)->first());
    }

    public function businessType(string $code): ?BusinessType
    {
        return $this->businessTypes()->get($code);
    }

    /** @return list<string> */
    public function moduleCodes(): array
    {
        return Module::query()->where('status', CatalogStatus::Active)->pluck('code')->all();
    }

    /** @return list<string> */
    public function templateCodes(): array
    {
        return WebsiteTemplate::query()->where('status', CatalogStatus::Active)->pluck('code')->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'business_types' => $this->businessTypes()->values()->map(fn (BusinessType $type) => [
                'code' => $type->code,
                'name' => $type->name,
                'description' => $type->description,
                'icon' => $type->configuration['icon'] ?? 'business',
                'engines' => $type->engines->map(fn (Engine $engine) => ['code' => $engine->code, 'name' => $engine->name])->values(),
                'modules' => $type->modules
                    ->filter(fn (Module $module) => $module->pivot->enabled && $module->isActive())
                    ->pluck('code')
                    ->values(),
                'required_modules' => $type->engines->flatMap->requiredModuleCodes()->unique()->values(),
                'templates' => array_values($type->configuration['website_templates'] ?? []),
            ]),
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
                    'depends_on' => $module->dependencies->pluck('code')->values(),
                ]),
            'templates' => WebsiteTemplate::query()
                ->where('status', CatalogStatus::Active)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (WebsiteTemplate $template) => [
                    'code' => $template->code,
                    'name' => $template->name,
                    'description' => $template->description,
                    'theme' => $template->theme(),
                ]),
            'brand_colors' => config('autowave.onboarding.brand_colors', []),
            'domain_suffix' => '.'.Hostname::normalize(config('autowave.root_domain')),
        ];
    }
}
