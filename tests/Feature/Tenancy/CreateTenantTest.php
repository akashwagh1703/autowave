<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Domain\Enums\DomainStatus;
use App\Domain\Engine\Services\EngineManager;
use App\Domain\Module\Services\ModuleManager;
use App\Domain\RBAC\Models\Role;
use App\Domain\Tenant\Events\TenantCreated;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Tenant\Support\TenantSlug;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CreateTenantTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_tenant_is_fully_provisioned_from_its_business_type(): void
    {
        Event::fake([TenantCreated::class]);
        $owner = User::factory()->create();

        $tenant = $this->createTenant('ABC Salon', 'beauty_salon', $owner);

        $this->assertSame('abc-salon', $tenant->slug);
        $this->assertSame('1.0', $tenant->business_type_version);
        $this->assertTrue($tenant->isActive());

        $membership = $tenant->memberships()->sole();
        $this->assertSame($owner->id, $membership->user_id);
        $this->assertSame(['owner'], app(TenantContext::class)->run($tenant, fn () => $membership->roles()->pluck('slug')->all()));

        $expectedModules = config('catalog.business_types.beauty_salon.modules');
        $expectedModules[] = 'payments';
        $this->assertEqualsCanonicalizing($expectedModules, app(ModuleManager::class)->enabledCodes($tenant));
        $this->assertEqualsCanonicalizing(['service', 'booking', 'commerce'], app(EngineManager::class)->enabledCodes($tenant));

        $domain = $tenant->primaryDomain;
        $this->assertSame('abc-salon.autowave.test', $domain->domain);
        $this->assertSame(DomainStatus::Active, $domain->status);

        $context = app(TenantContext::class);
        $context->set($tenant);
        $this->assertSame('ABC Salon', $context->setting('branding')['business_name']);
        $this->assertSame('Staff', $context->setting('booking_resource_label'));

        Event::assertDispatched(TenantCreated::class, fn (TenantCreated $event) => $event->tenant->is($tenant) && $event->owner->is($owner));
    }

    public function test_slugs_are_unique_and_valid(): void
    {
        $first = $this->createTenant('ABC Salon');
        $second = $this->createTenant('ABC Salon');

        $this->assertSame('abc-salon', $first->slug);
        $this->assertSame('abc-salon-2', $second->slug);
        $this->assertSame('admin-business', TenantSlug::generate('Admin'));
        $this->assertFalse(TenantSlug::isValid('-bad-'));
        $this->assertFalse(TenantSlug::isValid('ab'));
        $this->assertFalse(TenantSlug::isValid('www'));
    }

    public function test_creation_is_all_or_nothing(): void
    {
        Role::templates()->where('slug', 'owner')->delete();

        try {
            $this->createTenant('Broken Business');
            $this->fail('Tenant creation should have failed.');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(0, Tenant::query()->where('name', 'Broken Business')->count());
    }

    public function test_hidden_business_types_still_work_for_internal_tenants(): void
    {
        $tenant = $this->createTenant('AutoWave Internal', 'autowave_internal', options: ['is_internal' => true]);

        $this->assertTrue($tenant->is_internal);
        $this->assertContains('forms', app(ModuleManager::class)->enabledCodes($tenant));
    }
}
