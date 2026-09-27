<?php

namespace App\Domain\Module\Models;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A module enabled for a tenant. `version` pins the module version the tenant runs
 * so catalogue upgrades never silently change existing tenants.
 */
#[Fillable(['tenant_id', 'module_id', 'enabled', 'version', 'configuration'])]
class TenantModule extends Model
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'configuration' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
