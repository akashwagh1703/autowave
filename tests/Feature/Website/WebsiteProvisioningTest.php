<?php

namespace Tests\Feature\Website;

use App\Domain\Module\Exceptions\CatalogItemUnavailable;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Actions\ProvisionWebsite;
use App\Domain\Website\Models\WebsiteConfig;
use App\Domain\Website\Models\WebsiteSection;
use App\Domain\Website\Models\WebsiteTemplate;
use Database\Seeders\InternalTenantSeeder;
use Database\Seeders\PlatformAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class WebsiteProvisioningTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    public function test_templates_are_synced_from_the_catalogue(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys(config('catalog.website_templates')),
            WebsiteTemplate::query()->pluck('code')->all(),
        );
        $this->assertEquals(['hero' => 'dark', 'font' => 'serif', 'radius' => 'md'], WebsiteTemplate::query()->where('code', 'premium')->sole()->theme());
    }

    public function test_a_new_tenant_gets_the_recommended_template_and_its_sections(): void
    {
        $tenant = $this->createTenant('Chai Point', 'cafe');

        $this->context()->run($tenant, function () {
            $this->assertSame('premium', WebsiteConfig::query()->sole()->template->code);
            $this->assertSame(
                config('catalog.business_types.cafe.configuration.website_sections'),
                WebsiteSection::query()->orderBy('sort_order')->pluck('type')->all(),
            );
            // No booking engine → the hero asks visitors to get in touch instead.
            $this->assertSame('contact', WebsiteSection::query()->where('type', 'hero')->sole()->configuration['cta']);
        });
    }

    public function test_catalogue_only_keys_are_not_copied_into_settings(): void
    {
        $tenant = $this->createTenant();

        $keys = $this->context()->run($tenant, fn () => TenantSetting::query()->pluck('key')->all());

        $this->assertEqualsCanonicalizing(['branding', 'business_profile', 'dashboard_widgets', 'booking_resource_label'], $keys);
    }

    public function test_an_unknown_template_aborts_tenant_creation(): void
    {
        try {
            $this->createTenant('Broken Business', options: ['website_template' => 'neon']);
            $this->fail('Tenant creation should have failed.');
        } catch (CatalogItemUnavailable) {
        }

        $this->assertFalse(Tenant::query()->where('name', 'Broken Business')->exists());
    }

    public function test_provisioning_is_idempotent_and_backfills_older_tenants(): void
    {
        $tenant = $this->createTenant();
        $provision = app(ProvisionWebsite::class);

        $provision->ensureFor($tenant);
        $sections = $this->context()->run($tenant, fn () => WebsiteSection::query()->count());
        $this->assertSame(count(config('catalog.business_types.beauty_salon.configuration.website_sections')), $sections);

        // A tenant created before websites existed.
        $this->context()->run($tenant, function () {
            WebsiteSection::query()->delete();
            WebsiteConfig::query()->delete();
        });

        $provision->ensureFor($tenant);

        $this->context()->run($tenant, function () use ($sections) {
            $this->assertSame(1, WebsiteConfig::query()->count());
            $this->assertSame($sections, WebsiteSection::query()->count());
        });
    }

    public function test_reseeding_backfills_the_internal_tenant_website(): void
    {
        $this->seed([PlatformAdminSeeder::class, InternalTenantSeeder::class]);
        $tenant = Tenant::query()->where('slug', config('autowave.internal_tenant.slug'))->sole();

        $this->context()->run($tenant, function () {
            $this->assertSame('corporate', WebsiteConfig::query()->sole()->template->code);
            WebsiteSection::query()->delete();
            WebsiteConfig::query()->delete();
        });

        $this->seed(InternalTenantSeeder::class);

        $this->context()->run($tenant, fn () => $this->assertTrue(WebsiteConfig::query()->exists() && WebsiteSection::query()->exists()));
    }

    public function test_provisioning_outside_the_tenant_context_is_refused(): void
    {
        $tenant = $this->createTenant();

        $this->expectException(\LogicException::class);
        app(ProvisionWebsite::class)->handle($tenant, $tenant->businessType);
    }

    public function test_website_data_is_isolated_between_tenants(): void
    {
        $salon = $this->createTenant('ABC Salon');
        $turf = $this->createTenant('ABC Turf', 'turf');

        $this->assertSame(0, WebsiteSection::query()->count(), 'Queries without a tenant must fail closed.');
        $this->assertSame(0, WebsiteConfig::query()->count());

        $salonSectionIds = $this->context()->run($salon, fn () => WebsiteSection::query()->pluck('id'));

        $this->context()->run($turf, function () use ($salonSectionIds, $turf) {
            $this->assertSame(0, WebsiteSection::query()->whereIn('id', $salonSectionIds)->count());
            $this->assertSame([$turf->id], WebsiteConfig::query()->pluck('tenant_id')->all());
        });
    }

    public function test_switching_template_keeps_all_content(): void
    {
        $tenant = $this->createTenant();

        $this->context()->run($tenant, function () {
            $before = WebsiteSection::query()->orderBy('id')->get(['id', 'type', 'configuration'])->toArray();

            WebsiteConfig::query()->sole()->update(['website_template_id' => WebsiteTemplate::query()->where('code', 'minimal')->value('id')]);

            $this->assertSame($before, WebsiteSection::query()->orderBy('id')->get(['id', 'type', 'configuration'])->toArray());
        });

        $this->get($this->siteUrl('abc-salon.autowave.test'))
            ->assertInertia(fn (Assert $page) => $page->where('template.code', 'minimal')->where('template.radius', 'none'));
    }

    public function test_disabled_sections_are_not_rendered_and_unpublished_sites_are_offline(): void
    {
        $tenant = $this->createTenant();

        $this->context()->run($tenant, fn () => WebsiteSection::query()->where('type', 'about')->update(['enabled' => false]));

        $this->get($this->siteUrl('abc-salon.autowave.test'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where(
                'sections',
                fn ($sections) => ! collect($sections)->pluck('type')->contains('about'),
            ));

        $this->context()->run($tenant, fn () => WebsiteConfig::query()->update(['status' => 'draft']));

        $this->get($this->siteUrl('abc-salon.autowave.test'))->assertNotFound();
    }
}
