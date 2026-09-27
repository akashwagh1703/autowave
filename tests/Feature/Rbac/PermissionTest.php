<?php

namespace Tests\Feature\Rbac;

use App\Domain\RBAC\Actions\AssignRole;
use App\Domain\RBAC\Models\Permission;
use App\Domain\RBAC\Models\Role;
use App\Domain\RBAC\Support\PermissionCatalog;
use App\Domain\RBAC\Support\PermissionResolver;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_template_roles_are_copied_into_each_tenant(): void
    {
        $tenant = $this->createTenant();

        $this->assertEqualsCanonicalizing(
            array_keys(config('rbac.roles')),
            Role::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('slug')->all(),
        );
    }

    public function test_owner_has_every_permission_including_new_ones(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);

        Permission::query()->create(['key' => 'future.feature', 'group' => 'future']);

        $permissions = app(TenantContext::class)->run($tenant, fn () => app(PermissionResolver::class)->permissionsFor($owner));

        $this->assertContains('future.feature', $permissions);
        $this->assertEmpty(array_diff(PermissionCatalog::keys(), $permissions));
    }

    public function test_the_same_user_has_different_permissions_per_tenant(): void
    {
        $salon = $this->createTenant('ABC Salon');
        $turf = $this->createTenant('ABC Turf', 'turf');
        $user = User::factory()->create();
        $this->addMember($salon, $user, 'staff');
        $this->addMember($turf, $user, 'manager');

        $context = app(TenantContext::class);
        $resolver = app(PermissionResolver::class);

        $context->run($salon, function () use ($user, $resolver) {
            $this->assertTrue($user->can('appointments.view'));
            $this->assertFalse($user->can('leads.view'));
            $this->assertFalse($resolver->allows($user, 'settings.view'));
        });

        $context->run($turf, function () use ($user) {
            $this->assertTrue($user->can('leads.view'));
            $this->assertTrue($user->can('settings.view'));
            $this->assertFalse($user->can('settings.update'));
        });

        $this->assertFalse($user->can('appointments.view'), 'No permissions without a tenant.');
    }

    public function test_settings_page_requires_the_settings_permission(): void
    {
        $tenant = $this->createTenant();
        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');

        $this->actingAs($this->ownerOf($tenant))
            ->get($this->appUrl('/settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('business/Settings')->where('canUpdate', true));

        $this->actingAs($staff)
            ->get($this->appUrl('/settings'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 403));
    }

    public function test_permissions_are_shared_with_the_frontend_for_the_current_tenant_only(): void
    {
        $tenant = $this->createTenant();
        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');

        $this->actingAs($staff)
            ->get($this->appUrl('/dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('permissions', ['appointments.update', 'appointments.view', 'customers.view']));
    }

    public function test_roles_cannot_be_assigned_across_tenants(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $membershipInA = $a->memberships()->firstOrFail();
        $roleInB = Role::withoutTenantScope()->where('tenant_id', $b->id)->where('slug', 'manager')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);

        app(AssignRole::class)->handle($membershipInA, $roleInB);
    }

    public function test_the_database_rejects_cross_tenant_role_assignments(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $membershipInA = $a->memberships()->firstOrFail();
        $roleInB = Role::withoutTenantScope()->where('tenant_id', $b->id)->where('slug', 'manager')->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('user_roles')->insert([
            'tenant_id' => $a->id,
            'tenant_user_id' => $membershipInA->id,
            'role_id' => $roleInB->id,
        ]);
    }

    public function test_wildcard_permissions_expand_from_the_catalogue(): void
    {
        $this->assertEqualsCanonicalizing(
            ['leads.view', 'leads.create', 'leads.update', 'leads.assign', 'leads.delete'],
            PermissionCatalog::expand(['leads.*']),
        );
    }
}
