<?php

namespace App\Domain\Module\Models;

use App\Support\Enums\CatalogStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'name', 'description', 'type', 'version', 'status', 'configuration', 'sort_order'])]
class Module extends Model
{
    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'configuration' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === CatalogStatus::Active;
    }

    /**
     * Modules this module requires.
     */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'module_dependencies', 'module_id', 'depends_on_module_id');
    }

    /**
     * Modules that require this module.
     */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'module_dependencies', 'depends_on_module_id', 'module_id');
    }
}
