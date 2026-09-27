<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Domain\Enums\DomainStatus;
use App\Domain\Domain\Enums\DomainType;
use App\Domain\Domain\Models\Domain;
use App\Domain\Domain\Services\DomainResolver;
use App\Domain\Domain\Support\Hostname;
use App\Domain\Module\Services\ModuleManager;
use App\Domain\Tenant\Enums\TenantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class DomainResolutionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_each_tenant_subdomain_serves_only_that_tenant(): void
    {
        $salon = $this->createTenant('ABC Salon', options: ['slug' => 'abc-salon']);
        $this->createTenant('XYZ Clinic', 'clinic', options: ['slug' => 'xyz-clinic']);

        $this->get($this->siteUrl('abc-salon.autowave.test'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('website/Home')
                ->where('business.name', 'ABC Salon')
                ->where('tenant.id', $salon->id));

        $this->get($this->siteUrl('xyz-clinic.autowave.test'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('business.name', 'XYZ Clinic'));
    }

    public function test_unknown_hosts_return_404(): void
    {
        $this->get($this->siteUrl('nobody.autowave.test'))->assertNotFound();
        $this->get($this->siteUrl('example.org'))->assertNotFound();
    }

    public function test_custom_domains_resolve_once_active(): void
    {
        $tenant = $this->createTenant('ABC Salon');

        $domain = Domain::query()->create([
            'tenant_id' => $tenant->id,
            'domain' => 'WWW.ABCSALON.IN.',
            'type' => DomainType::Custom,
            'status' => DomainStatus::Pending,
        ]);

        $this->assertSame('www.abcsalon.in', $domain->domain);
        $this->get($this->siteUrl('www.abcsalon.in'))->assertNotFound();

        $domain->update(['status' => DomainStatus::Active]);

        $this->get($this->siteUrl('www.abcsalon.in'))->assertOk();
    }

    public function test_disabled_domains_stop_resolving_immediately(): void
    {
        $tenant = $this->createTenant('ABC Salon', options: ['slug' => 'abc-salon']);
        $host = 'abc-salon.autowave.test';

        $this->get($this->siteUrl($host))->assertOk();

        $tenant->primaryDomain->update(['status' => DomainStatus::Disabled]);

        $this->get($this->siteUrl($host))->assertNotFound();
    }

    public function test_suspended_tenants_are_not_served(): void
    {
        $tenant = $this->createTenant('ABC Salon', options: ['slug' => 'abc-salon']);

        $this->get($this->siteUrl('abc-salon.autowave.test'))->assertOk();

        $tenant->update(['status' => TenantStatus::Suspended]);

        $this->get($this->siteUrl('abc-salon.autowave.test'))->assertNotFound();
    }

    public function test_site_is_hidden_when_the_website_module_is_disabled(): void
    {
        $tenant = $this->createTenant('ABC Salon', options: ['slug' => 'abc-salon']);

        app(ModuleManager::class)->disable($tenant, 'website');

        $this->get($this->siteUrl('abc-salon.autowave.test'))->assertNotFound();
    }

    public function test_platform_hosts_never_resolve_to_a_tenant(): void
    {
        $tenant = $this->createTenant('ABC Salon');
        $resolver = app(DomainResolver::class);

        foreach (Hostname::platformHosts() as $host) {
            Domain::query()->forceCreate([
                'tenant_id' => $tenant->id,
                'domain' => $host,
                'type' => DomainType::Custom,
                'status' => DomainStatus::Active,
            ]);

            $this->assertNull($resolver->resolve($host), "Platform host [{$host}] resolved to a tenant.");
        }
    }

    public function test_hostnames_are_normalised(): void
    {
        $this->assertSame('abc.autowave.test', Hostname::normalize(' ABC.AutoWave.Test.:8000 '));
        $this->assertSame('', Hostname::normalize('bad host'));
        $this->assertSame('', Hostname::normalize(null));

        $tenant = $this->createTenant('ABC Salon', options: ['slug' => 'abc-salon']);

        $this->assertSame($tenant->id, app(DomainResolver::class)->resolve('ABC-SALON.autowave.test:8000')?->id);
    }

    public function test_reserved_subdomains_cannot_become_tenant_slugs(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createTenant('Admin', options: ['slug' => 'admin']);
    }
}
