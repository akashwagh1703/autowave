<?php

namespace App\Domain\Website\Actions;

use App\Domain\Business\Models\BusinessType;
use App\Domain\Engine\Services\EngineManager;
use App\Domain\Module\Exceptions\CatalogItemUnavailable;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteConfig;
use App\Domain\Website\Models\WebsiteSection;
use App\Domain\Website\Models\WebsiteTemplate;
use LogicException;

/**
 * Creates a tenant's default website: template, theme, SEO and the business type's
 * sections. Idempotent — an existing configuration is returned untouched.
 * Must run inside TenantContext::run() for the tenant.
 */
class ProvisionWebsite
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EngineManager $engines,
    ) {}

    /**
     * @param  array{tagline?: ?string, about?: ?string, primary_color?: ?string}  $content
     */
    public function handle(Tenant $tenant, ?BusinessType $type, ?string $templateCode = null, array $content = []): WebsiteConfig
    {
        if ($this->context->id() !== $tenant->id) {
            throw new LogicException('ProvisionWebsite must run inside the tenant context.');
        }

        $existing = WebsiteConfig::query()->first();

        if ($existing) {
            return $existing;
        }

        $configuration = $type?->configuration ?? [];
        $template = $this->template($templateCode ?? ($configuration['website_templates'][0] ?? null));

        $config = WebsiteConfig::query()->create([
            'website_template_id' => $template->id,
            'theme' => ['primary_color' => $content['primary_color'] ?? null],
            'seo' => [
                'title' => $tenant->name,
                'description' => $content['tagline'] ?? $content['about'] ?? $type?->description,
            ],
            'status' => 'published',
            'published_at' => now(),
        ]);

        $bookable = in_array('booking', $this->engines->enabledCodes($tenant), true);

        foreach (array_values($configuration['website_sections'] ?? ['header', 'hero', 'about', 'contact', 'footer']) as $index => $sectionType) {
            WebsiteSection::query()->create([
                'type' => $sectionType,
                'sort_order' => ($index + 1) * 10,
                'enabled' => true,
                'configuration' => $this->defaults($sectionType, $tenant, $content, $bookable),
            ]);
        }

        return $config;
    }

    /**
     * Backfill for tenants created before websites existed; content comes from their settings.
     */
    public function ensureFor(Tenant $tenant): WebsiteConfig
    {
        return $this->context->run($tenant, function (Tenant $tenant) {
            $branding = $this->context->setting('branding', []);

            return $this->handle($tenant, $tenant->businessType, null, [
                'primary_color' => $branding['primary_color'] ?? null,
                'tagline' => $branding['tagline'] ?? null,
                'about' => $this->context->setting('business_profile', [])['description'] ?? null,
            ]);
        });
    }

    private function template(?string $code): WebsiteTemplate
    {
        $template = $code
            ? WebsiteTemplate::query()->where('code', $code)->first()
            : WebsiteTemplate::query()->orderBy('sort_order')->first();

        if (! $template || ! $template->isActive()) {
            throw new CatalogItemUnavailable("Website template [{$code}] does not exist or is not active.");
        }

        return $template;
    }

    /** @return array<string, mixed> */
    private function defaults(string $type, Tenant $tenant, array $content, bool $bookable): array
    {
        return match ($type) {
            'hero' => [
                'headline' => $tenant->name,
                'subheadline' => $content['tagline'] ?? null,
                'cta' => $bookable ? 'book' : 'contact',
            ],
            'about' => [
                'heading' => 'About us',
                'body' => $content['about'] ?? null,
            ],
            'contact' => [
                'heading' => 'Get in touch',
            ],
            default => [],
        };
    }
}
