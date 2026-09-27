<?php

namespace App\Domain\RBAC\Support;

use App\Domain\RBAC\Models\Permission;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;

/**
 * Resolves a user's permissions in the current tenant. Scoped per request/job.
 *
 * A user has no tenant permissions unless the user, the tenant and the membership
 * are all active. Platform admins get no implicit tenant permissions.
 */
class PermissionResolver
{
    /** @var array<string, list<string>> */
    private array $cache = [];

    public function __construct(private readonly TenantContext $context) {}

    /** @return list<string> */
    public function permissionsFor(User $user): array
    {
        $tenant = $this->context->get();

        if (! $tenant) {
            return [];
        }

        return $this->cache[$user->getKey().':'.$tenant->getKey()] ??= $this->resolve($user, $tenant->getKey(), $tenant->isActive());
    }

    public function allows(User $user, string $permission): bool
    {
        return in_array($permission, $this->permissionsFor($user), true);
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /** @return list<string> */
    private function resolve(User $user, int $tenantId, bool $tenantActive): array
    {
        if (! $tenantActive || ! $user->isActive()) {
            return [];
        }

        $membership = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active)
            ->first();

        if (! $membership) {
            return [];
        }

        $roles = $membership->roles()->with('permissions:id,key')->get();

        if ($roles->contains('grants_all', true)) {
            return Permission::query()->orderBy('key')->pluck('key')->all();
        }

        return $roles->flatMap->permissions->pluck('key')->unique()->sort()->values()->all();
    }
}
