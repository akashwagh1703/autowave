<?php

namespace Tests\Feature\Tenancy;

use App\Domain\RBAC\Models\Role;
use App\Domain\Tenant\Exceptions\CrossTenantWrite;
use App\Domain\Tenant\Exceptions\MissingTenantContext;
use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Cross-tenant isolation harness for tenant-owned models (ADR-002).
 * New tenant-owned models should add equivalent cases.
 */
class TenantIsolationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
    }

    public function test_queries_fail_closed_without_a_tenant(): void
    {
        $this->createTenant('Tenant A');

        $this->assertFalse($this->context->check());
        $this->assertSame(0, TenantSetting::query()->count());
        $this->assertSame(0, Role::query()->count());
        $this->assertGreaterThan(0, TenantSetting::withoutTenantScope()->count());
    }

    public function test_reads_are_scoped_to_the_current_tenant(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');

        $this->context->run($a, function () use ($a) {
            $this->assertSame('Tenant A', TenantSetting::query()->where('key', 'branding')->value('value')['business_name']);
            $this->assertTrue(Role::query()->get()->every(fn (Role $role) => $role->tenant_id === $a->id));
        });

        $this->context->run($b, function () use ($a) {
            $this->assertSame('Tenant B', TenantSetting::query()->where('key', 'branding')->value('value')['business_name']);
            $this->assertSame(0, TenantSetting::query()->where('tenant_id', $a->id)->count());
            $this->assertNull(Role::query()->whereKey(Role::withoutTenantScope()->where('tenant_id', $a->id)->value('id'))->first());
        });
    }

    public function test_tenant_id_is_filled_from_the_context(): void
    {
        $a = $this->createTenant('Tenant A');

        $setting = $this->context->run($a, fn () => TenantSetting::query()->create(['key' => 'custom', 'value' => ['x' => 1]]));

        $this->assertSame($a->id, $setting->tenant_id);
    }

    public function test_creating_without_a_tenant_throws(): void
    {
        $this->expectException(MissingTenantContext::class);

        TenantSetting::query()->create(['key' => 'orphan', 'value' => []]);
    }

    public function test_writing_into_another_tenant_throws(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');

        $this->expectException(CrossTenantWrite::class);

        $this->context->run($a, fn () => TenantSetting::query()->create([
            'tenant_id' => $b->id,
            'key' => 'injected',
            'value' => [],
        ]));
    }

    public function test_tenant_id_cannot_be_changed(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');

        $this->expectException(CrossTenantWrite::class);

        $this->context->run($a, function () use ($b) {
            $setting = TenantSetting::query()->where('key', 'branding')->firstOrFail();
            $setting->tenant_id = $b->id;
            $setting->save();
        });
    }

    public function test_run_restores_the_previous_tenant_even_on_failure(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');

        $this->context->set($a);

        try {
            $this->context->run($b, fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        $this->assertSame($a->id, $this->context->id());
    }

    public function test_template_roles_are_invisible_to_tenants(): void
    {
        $a = $this->createTenant('Tenant A');

        $this->assertGreaterThan(0, Role::templates()->count());

        $this->context->run($a, fn () => $this->assertSame(0, Role::query()->whereNull('tenant_id')->count()));
    }
}
