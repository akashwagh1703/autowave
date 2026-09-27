<?php

namespace Tests\Concerns;

use App\Domain\RBAC\Actions\AssignRole;
use App\Domain\RBAC\Models\Role;
use App\Domain\Tenant\Actions\CreateTenant;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;

trait CreatesTenants
{
    /**
     * A fully provisioned tenant (roles, modules, engines, settings, subdomain).
     *
     * @param  array<string, mixed>  $options
     */
    protected function createTenant(string $name = 'ABC Salon', string $businessType = 'beauty_salon', ?User $owner = null, array $options = []): Tenant
    {
        return app(CreateTenant::class)->handle($owner ?? User::factory()->create(), $name, $businessType, $options);
    }

    protected function addMember(Tenant $tenant, User $user, string $roleSlug, MembershipStatus $status = MembershipStatus::Active): TenantUser
    {
        return app(TenantContext::class)->run($tenant, function (Tenant $tenant) use ($user, $roleSlug, $status) {
            $membership = TenantUser::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'status' => $status,
                'joined_at' => now(),
            ]);

            app(AssignRole::class)->handle($membership, Role::query()->where('slug', $roleSlug)->firstOrFail());

            return $membership;
        });
    }

    protected function ownerOf(Tenant $tenant): User
    {
        return $tenant->memberships()->orderBy('id')->firstOrFail()->user;
    }
}
