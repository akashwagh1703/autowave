<?php

namespace App\Domain\RBAC\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A role within one tenant. Rows with tenant_id = null are platform templates,
 * copied into each new tenant and never assigned directly.
 */
#[Fillable(['tenant_id', 'slug', 'name', 'description', 'grants_all', 'is_locked'])]
class Role extends Model
{
    use BelongsToTenant;

    private static bool $creatingTemplate = false;

    protected function casts(): array
    {
        return [
            'grants_all' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function isTemplate(): bool
    {
        return $this->tenant_id === null;
    }

    public static function templates(): Builder
    {
        return static::withoutTenantScope()->whereNull('tenant_id');
    }

    /**
     * Create or update a template role. The only supported way to create tenant-less roles.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function syncTemplate(string $slug, array $attributes): self
    {
        static::$creatingTemplate = true;

        try {
            $role = static::templates()->firstOrNew(['slug' => $slug]);
            $role->fill([...$attributes, 'tenant_id' => null])->save();

            return $role;
        } finally {
            static::$creatingTemplate = false;
        }
    }

    public function allowsTenantlessCreation(): bool
    {
        return static::$creatingTemplate;
    }
}
