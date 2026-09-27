<?php

namespace App\Domain\RBAC\Actions;

use App\Domain\RBAC\Models\Role;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * Copies the platform template roles (and their permissions) into a tenant.
 * Idempotent: existing tenant roles with the same slug are kept.
 */
class ProvisionTenantRoles
{
    /** @return Collection<string, Role> keyed by slug */
    public function handle(Tenant $tenant): Collection
    {
        return Role::templates()
            ->with('permissions:id')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(function (Role $template) use ($tenant) {
                $role = Role::withoutTenantScope()->firstOrCreate(
                    ['tenant_id' => $tenant->getKey(), 'slug' => $template->slug],
                    [
                        'name' => $template->name,
                        'description' => $template->description,
                        'grants_all' => $template->grants_all,
                        'is_locked' => $template->is_locked,
                    ],
                );

                if ($role->wasRecentlyCreated) {
                    $role->permissions()->sync($template->permissions->modelKeys());
                }

                return [$role->slug => $role];
            });
    }
}
