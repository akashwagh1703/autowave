<?php

namespace App\Domain\RBAC\Actions;

use App\Domain\RBAC\Models\Permission;
use App\Domain\RBAC\Models\Role;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;
use Illuminate\Support\Collection;

/**
 * Copies the platform template roles (and their permissions) into a tenant.
 * Idempotent: existing tenant roles with the same slug are kept.
 */
class ProvisionTenantRoles
{
    /** Tenant setting listing the permission groups already backfilled for that tenant. */
    public const BACKFILLED_GROUPS_KEY = 'rbac_backfilled_groups';

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

    /**
     * For tenants created before a permission group existed: give each tenant role its template's
     * permissions in that group. Runs once per tenant and group, and skips roles that already hold
     * any permission of the group, so later edits by the tenant are never overwritten.
     *
     * @param  list<string>  $groups
     * @return int number of roles that received permissions
     */
    public function grantNewPermissionGroups(Tenant $tenant, array $groups): int
    {
        $done = TenantSetting::withoutTenantScope()
            ->where('tenant_id', $tenant->getKey())
            ->where('key', self::BACKFILLED_GROUPS_KEY)
            ->first()?->value ?? [];

        $pending = array_values(array_diff($groups, $done));

        if ($pending === []) {
            return 0;
        }

        $templates = Role::templates()->with(['permissions' => fn ($query) => $query->whereIn('group', $pending)])->get()->keyBy('slug');
        $granted = 0;

        Role::withoutTenantScope()
            ->where('tenant_id', $tenant->getKey())
            ->with(['permissions' => fn ($query) => $query->whereIn('group', $pending)])
            ->get()
            ->each(function (Role $role) use ($templates, $pending, &$granted) {
                $template = $templates->get($role->slug);

                if (! $template || $role->grants_all) {
                    return;
                }

                $add = [];

                foreach ($pending as $group) {
                    $hasAny = $role->permissions->contains(fn (Permission $permission) => $permission->group === $group);

                    if (! $hasAny) {
                        $add = [...$add, ...$template->permissions->where('group', $group)->modelKeys()];
                    }
                }

                if ($add !== []) {
                    $role->permissions()->syncWithoutDetaching($add);
                    $granted++;
                }
            });

        TenantSetting::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenant->getKey(), 'key' => self::BACKFILLED_GROUPS_KEY],
            ['value' => array_values(array_unique([...$done, ...$pending]))],
        );

        return $granted;
    }
}
