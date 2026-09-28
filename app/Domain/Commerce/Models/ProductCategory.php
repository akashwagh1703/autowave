<?php

namespace App\Domain\Commerce\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A group on the product list (e.g. Hair care). Deleting it leaves its products uncategorised. */
#[Fillable(['tenant_id', 'name', 'sort_order'])]
class ProductCategory extends Model
{
    use BelongsToTenant;

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }
}
