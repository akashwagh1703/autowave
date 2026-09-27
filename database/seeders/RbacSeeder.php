<?php

namespace Database\Seeders;

use App\Domain\RBAC\Models\Permission;
use App\Domain\RBAC\Models\Role;
use App\Domain\RBAC\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Syncs config/rbac.php into permissions and template roles. Idempotent.
 * Existing tenant roles are not changed (tenants may have customised them).
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::all() as $key => $description) {
            Permission::query()->updateOrCreate(['key' => $key], [
                'group' => Str::before($key, '.'),
                'description' => $description,
            ]);
        }

        $permissionIds = Permission::query()->pluck('id', 'key');

        foreach (config('rbac.roles') as $slug => $role) {
            $template = Role::syncTemplate($slug, [
                'name' => $role['name'],
                'description' => $role['description'] ?? null,
                'grants_all' => $role['grants_all'] ?? false,
                'is_locked' => $role['locked'] ?? false,
            ]);

            $template->permissions()->sync(
                collect(PermissionCatalog::expand($role['permissions'] ?? []))->map(fn (string $key) => $permissionIds[$key])->all()
            );
        }
    }
}
