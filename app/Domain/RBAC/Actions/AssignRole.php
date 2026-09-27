<?php

namespace App\Domain\RBAC\Actions;

use App\Domain\RBAC\Models\Role;
use App\Domain\RBAC\Support\PermissionResolver;
use App\Domain\Tenant\Models\TenantUser;
use InvalidArgumentException;

class AssignRole
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function handle(TenantUser $membership, Role $role): void
    {
        if ($role->tenant_id === null || $role->tenant_id !== $membership->tenant_id) {
            throw new InvalidArgumentException('A role can only be assigned within its own tenant.');
        }

        $membership->roles()->syncWithoutDetaching([
            $role->getKey() => ['tenant_id' => $membership->tenant_id],
        ]);

        $this->permissions->flush();
    }
}
