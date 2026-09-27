<?php

namespace App\Domain\Business\Models;

use App\Domain\Engine\Models\Engine;
use App\Domain\Module\Models\Module;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Enums\CatalogStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned preset of engines, modules and configuration (ADR-005).
 * Each version is its own row; tenants keep the version they were created with.
 */
#[Fillable(['code', 'name', 'description', 'version', 'status', 'is_public', 'configuration', 'sort_order'])]
class BusinessType extends Model
{
    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'is_public' => 'boolean',
            'configuration' => 'array',
        ];
    }

    public function engines(): BelongsToMany
    {
        return $this->belongsToMany(Engine::class, 'business_type_engines');
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'business_type_modules')
            ->withPivot(['enabled', 'configuration']);
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', CatalogStatus::Active);
    }

    /**
     * The newest active version of a business type code.
     */
    public static function latestActive(string $code): ?self
    {
        return static::query()
            ->active()
            ->where('code', $code)
            ->get()
            ->sortByDesc(fn (self $type) => $type->version, SORT_NATURAL)
            ->first();
    }
}
